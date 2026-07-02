#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
import sys
import re
import json
import argparse
import subprocess
import tempfile
import shutil
import time
import threading
import concurrent.futures

# Pillow opsiyoneldir: yoksa kırpma/ikinci geçiş atlanır, ana OCR yine çalışır.
try:
    from PIL import Image, ImageEnhance, ImageFilter
    PIL_AVAILABLE = True
except ImportError:
    PIL_AVAILABLE = False

# Bir satırın "barkod satırı" sayılması için gereken en az rakam sayısı.
MIN_BARCODE_DIGITS = 10

# Bu güvenin altındaki barkod satırları kırpılıp psm 7 ile ikinci kez okunur.
RETRY_CONF_THRESHOLD = 88.0

_CONVERT_BIN = shutil.which("convert") or shutil.which("magick")

# RapidOCR motoru süreç başına bir kez yüklenir; import edilemezse sessizce devre dışı.
_rapidocr_engine = None
_rapidocr_failed = False
_rapidocr_lock = threading.Lock()


def log_progress(msg: str) -> None:
    sys.stderr.write(f"OCR_PROGRESS: {msg}\n")
    sys.stderr.flush()


def get_rapidocr():
    """Lazily initializes the optional RapidOCR referee engine."""
    global _rapidocr_engine, _rapidocr_failed
    if _rapidocr_failed:
        return None
    if _rapidocr_engine is None:
        with _rapidocr_lock:
            if _rapidocr_engine is None and not _rapidocr_failed:
                try:
                    from rapidocr_onnxruntime import RapidOCR
                    _rapidocr_engine = RapidOCR()
                    log_progress("RapidOCR hakem motoru yüklendi.")
                except Exception:
                    _rapidocr_failed = True
                    return None
    return _rapidocr_engine


def rapidocr_read_digits(img_path: str) -> str:
    """Reads an image crop with RapidOCR and returns digits only ('' on failure)."""
    engine = get_rapidocr()
    if engine is None:
        return ""
    try:
        with _rapidocr_lock:
            result, _ = engine(img_path)
        if not result:
            return ""
        text = "".join(item[1] for item in result)
        return re.sub(r"\D", "", text)
    except Exception:
        return ""


def get_page_count(pdf_path: str) -> int:
    result = subprocess.run(
        ["pdfinfo", pdf_path],
        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, encoding="utf-8"
    )
    m = re.search(r"^Pages:\s+(\d+)", result.stdout, re.MULTILINE)
    return int(m.group(1)) if m else 0


def all_pages_unrotated(pdf_path: str) -> bool:
    """pdfimages sayfa döndürmesini uygulamaz; herhangi bir sayfa döndürülmüşse
    pdftoppm ile render etmek gerekir."""
    result = subprocess.run(
        ["pdfinfo", "-f", "1", "-l", "100000", pdf_path],
        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, encoding="utf-8"
    )
    rotations = re.findall(r"^Page\s+\d+\s+rot:\s+(\d+)", result.stdout, re.MULTILINE)
    if not rotations:
        return False
    return all(int(r) % 360 == 0 for r in rotations)


def embedded_images_usable(pdf_path: str, page_count: int) -> bool:
    """Taranmış PDF tespiti: her sayfada tam 1 adet, yeterince büyük gömülü görüntü
    olmalı. Aksi halde (dijital PDF, logolu sayfa vb.) render moduna düşülür."""
    if page_count <= 0:
        return False
    result = subprocess.run(
        ["pdfimages", "-list", pdf_path],
        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, encoding="utf-8"
    )
    pages_seen = []
    for line in result.stdout.splitlines():
        parts = line.split()
        if len(parts) < 6 or not parts[0].isdigit():
            continue
        page, width, height = int(parts[0]), parts[3], parts[4]
        if not (width.isdigit() and height.isdigit()):
            return False
        if int(width) < 800 or int(height) < 800:
            return False
        pages_seen.append(page)
    if len(pages_seen) != page_count:
        return False
    return sorted(pages_seen) == list(range(1, page_count + 1))


def acquire_page_images(pdf_path: str, temp_dir: str) -> list:
    """Sayfa görüntülerini üretir. Öncelik: gömülü taramayı KAYIPSIZ çıkarmak
    (yeniden örnekleme bulanıklığı olmaz, daha hızlı). Uygun değilse 300 DPI render."""
    page_count = get_page_count(pdf_path)

    if all_pages_unrotated(pdf_path) and embedded_images_usable(pdf_path, page_count):
        prefix = os.path.join(temp_dir, "emb")
        try:
            subprocess.run(
                ["pdfimages", "-png", pdf_path, prefix],
                check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
            )
            images = sorted(
                os.path.join(temp_dir, f)
                for f in os.listdir(temp_dir)
                if f.startswith("emb-") and f.endswith(".png")
            )
            if len(images) == page_count:
                log_progress(f"Gömülü tarama görüntüleri kayıpsız çıkarıldı ({page_count} sayfa).")
                return images
            for img in images:
                os.remove(img)
        except Exception:
            pass

    subprocess.run(
        ["pdftoppm", "-png", "-r", "300", pdf_path, os.path.join(temp_dir, "page")],
        check=True
    )
    images = sorted(
        os.path.join(temp_dir, f)
        for f in os.listdir(temp_dir)
        if f.startswith("page-") and f.endswith(".png")
    )
    log_progress(f"Sayfalar 300 DPI render edildi ({len(images)} sayfa).")
    return images


def is_bilevel_image(img_path: str) -> bool:
    """1-bit (siyah/beyaz faks modu) taramaları tespit eder; bunlarda kontrast/keskinlik
    filtresi işe yaramaz, kenar yumuşatma (hafif blur) gerekir."""
    if not PIL_AVAILABLE:
        return False
    try:
        with Image.open(img_path) as im:
            if im.mode == "1":
                return True
            colors = im.convert("L").getcolors(4)
            return colors is not None and len(colors) <= 2
    except Exception:
        return False


def preprocess_image(img_path: str) -> str:
    """Deskew + moda uygun filtre. Çıktı yolu döner (başarısızsa orijinal yol)."""
    img_dir = os.path.dirname(img_path)
    clean_path = os.path.join(img_dir, "clean_" + os.path.basename(img_path))
    bilevel = is_bilevel_image(img_path)

    if _CONVERT_BIN:
        if bilevel:
            # 1-bit taramada testere kenarları yumuşatmak LSTM doğruluğunu artırır.
            args = [_CONVERT_BIN, img_path, "-colorspace", "gray",
                    "-deskew", "40%", "+repage", "-blur", "0x0.5", clean_path]
        else:
            args = [_CONVERT_BIN, img_path, "-colorspace", "gray",
                    "-deskew", "40%", "+repage",
                    "-level", "15%,85%", "-sharpen", "0x1.5", clean_path]
        try:
            subprocess.run(args, check=True, timeout=60,
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            return clean_path
        except Exception:
            pass

    if PIL_AVAILABLE:
        try:
            with Image.open(img_path) as im:
                gray = im.convert("L")
                if bilevel:
                    processed = gray.filter(ImageFilter.GaussianBlur(0.5))
                else:
                    processed = ImageEnhance.Contrast(gray).enhance(1.5)
                    processed = processed.filter(ImageFilter.SHARPEN)
                processed.save(clean_path)
            return clean_path
        except Exception:
            pass

    return img_path


def run_tesseract_tsv(img_path: str, psm: int, timeout: int = 90) -> list:
    """Tesseract'i TSV modunda çalıştırır; kelime kutuları ve güven skorlarını döner.

    Rakam whitelist'i korunur: eşleştirme yalnızca rakamlara bakar, belirsiz glifleri
    okuma anında rakama zorlamak sonradan düzeltmekten daha güvenilirdir.
    """
    custom_env = os.environ.copy()
    custom_env["OMP_THREAD_LIMIT"] = "1"

    result = subprocess.run([
        "tesseract", img_path, "stdout",
        "--psm", str(psm),
        "-l", "eng",
        "-c", "tessedit_char_whitelist=0123456789",
        "tsv"
    ], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
        encoding="utf-8", timeout=timeout, env=custom_env)

    words = []
    for line in result.stdout.splitlines()[1:]:
        parts = line.split("\t")
        if len(parts) != 12:
            continue
        try:
            level = int(parts[0])
            if level != 5:  # yalnızca kelime düzeyi
                continue
            text = parts[11].strip()
            if text == "":
                continue
            words.append({
                "block": int(parts[2]),
                "par": int(parts[3]),
                "line": int(parts[4]),
                "left": int(parts[6]),
                "top": int(parts[7]),
                "width": int(parts[8]),
                "height": int(parts[9]),
                "conf": float(parts[10]),
                "text": text,
            })
        except (ValueError, IndexError):
            continue
    return words


def build_lines_from_words(words: list) -> list:
    """TSV kelimelerini görsel satırlara gruplar. Her satır: metin, en düşük kelime
    güveni ve satırın birleşik sınır kutusu."""
    lines = {}
    order = []
    for w in words:
        key = (w["block"], w["par"], w["line"])
        if key not in lines:
            lines[key] = {"word_objs": [], "conf": 101.0,
                          "x0": w["left"], "y0": w["top"],
                          "x1": w["left"] + w["width"], "y1": w["top"] + w["height"]}
            order.append(key)
        entry = lines[key]
        entry["word_objs"].append(w)
        entry["conf"] = min(entry["conf"], w["conf"])
        entry["x0"] = min(entry["x0"], w["left"])
        entry["y0"] = min(entry["y0"], w["top"])
        entry["x1"] = max(entry["x1"], w["left"] + w["width"])
        entry["y1"] = max(entry["y1"], w["top"] + w["height"])

    result = []
    for key in order:
        entry = lines[key]
        text = " ".join(w["text"] for w in entry["word_objs"])
        digits = re.sub(r"\D", "", text)

        # İkinci geçiş kararı: satırda düşük güvenli bir barkod adayı kelime var mı?
        # (a) >=10 haneli tek kelime, veya (b) toplamda >=14 hane taşıyan satırda
        # >=6 haneli düşük güvenli bir parça (hücre içinde bölünmüş barkod ihtimali).
        retry = False
        for w in entry["word_objs"]:
            wd = re.sub(r"\D", "", w["text"])
            if w["conf"] < RETRY_CONF_THRESHOLD and (
                len(wd) >= 10 or (len(wd) >= 6 and len(digits) >= 14)
            ):
                retry = True
                break

        result.append({
            "text": text,
            "digits": digits,
            "conf": round(entry["conf"], 1),
            "bbox": (entry["x0"], entry["y0"], entry["x1"], entry["y1"]),
            "alts": [],
            "retry": retry,
        })
    return result


def reocr_line_crop(pre_image, bbox, temp_dir: str, bilevel: bool):
    """Düşük güvenli bir satırı kırpıp 3x büyüterek tek satır modunda (psm 7)
    yeniden okur. (digits, conf) döner; başarısızsa ('', -1)."""
    pad = 8
    x0 = max(0, bbox[0] - pad)
    y0 = max(0, bbox[1] - pad)
    x1 = min(pre_image.width, bbox[2] + pad)
    y1 = min(pre_image.height, bbox[3] + pad)
    if x1 - x0 < 10 or y1 - y0 < 5:
        return "", -1.0, None

    crop = pre_image.crop((x0, y0, x1, y1)).convert("L")
    crop = crop.resize((crop.width * 3, crop.height * 3), Image.LANCZOS)
    if bilevel:
        crop = crop.filter(ImageFilter.GaussianBlur(0.6))

    crop_path = os.path.join(temp_dir, f"crop_{x0}_{y0}_{os.getpid()}_{threading.get_ident()}.png")
    try:
        crop.save(crop_path)
        words = run_tesseract_tsv(crop_path, psm=7, timeout=30)
        if not words:
            return "", -1.0, crop_path
        text = " ".join(w["text"] for w in words)
        conf = min(w["conf"] for w in words)
        return text, conf, crop_path
    except Exception:
        return "", -1.0, crop_path


def ocr_page(img_path: str, page_no: int) -> list:
    """Bir sayfayı okur ve satır listesi döner: [{text, conf, alts}, ...].

    Akış: ön işleme -> TSV ana geçiş (psm 6) -> düşük güvenli barkod satırlarına
    kırpılmış psm 7 ikinci geçiş -> uyuşmazlıkta RapidOCR hakemi.
    """
    temp_dir = os.path.dirname(img_path)
    bilevel = is_bilevel_image(img_path)
    pre_path = preprocess_image(img_path)
    crop_paths = []

    try:
        words = run_tesseract_tsv(pre_path, psm=6)
        lines = build_lines_from_words(words)

        retried = 0
        if PIL_AVAILABLE:
            pre_image = None
            try:
                for line in lines:
                    # Yalnızca barkod adayı taşıyan (>=10 rakamlı kelimesi olan) ve o
                    # kelimesi düşük güvenli satırlar ikinci geçişe girer; tarih/sevk
                    # sütunlarının düşük güveni tek başına ikinci geçişi tetiklemez.
                    if not line["retry"]:
                        continue
                    if pre_image is None:
                        pre_image = Image.open(pre_path)
                    alt_text, alt_conf, crop_path = reocr_line_crop(
                        pre_image, line["bbox"], temp_dir, bilevel)
                    if crop_path:
                        crop_paths.append(crop_path)
                    alt_digits = re.sub(r"\D", "", alt_text)
                    if len(alt_digits) < MIN_BARCODE_DIGITS:
                        continue
                    retried += 1

                    if alt_digits == line["digits"]:
                        # İki geçiş aynı okuyor: okuma doğrulandı, güveni yükselt.
                        line["conf"] = max(line["conf"], alt_conf)
                        continue

                    # Uyuşmazlık: birincil metin (kelime yapısıyla) korunur, ikinci geçiş
                    # okuma alternatif olarak eşleştiriciye taşınır. Varsa RapidOCR hakemi
                    # üçüncü bir bağımsız okuma olarak eklenir.
                    line["alts"].append(alt_text)
                    referee = rapidocr_read_digits(crop_path) if crop_path else ""
                    if len(referee) >= MIN_BARCODE_DIGITS \
                            and referee not in (alt_digits, line["digits"]) \
                            and referee not in line["alts"]:
                        line["alts"].append(referee)
            finally:
                if pre_image is not None:
                    pre_image.close()

        if retried:
            log_progress(f"Sayfa {page_no}: {retried} düşük güvenli satır ikinci geçişle okundu.")

        return [{"text": l["text"], "conf": l["conf"], "alts": l["alts"]} for l in lines]
    finally:
        for p in crop_paths:
            if p and os.path.exists(p):
                os.remove(p)
        if pre_path != img_path and os.path.exists(pre_path):
            os.remove(pre_path)


def extract_text_mode(pdf_path: str) -> str:
    result = subprocess.run(
        ["pdftotext", "-layout", pdf_path, "-"],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        encoding="utf-8"
    )
    if result.returncode != 0:
        raise RuntimeError(f"pdftotext failed: {result.stderr}")
    return result.stdout


def extract_ocr_structured(pdf_path: str) -> list:
    """Tüm sayfaları OCR'lar; satır sırası korunarak tek liste döner."""
    temp_dir = tempfile.mkdtemp()
    try:
        page_images = acquire_page_images(pdf_path, temp_dir)
        if not page_images:
            raise RuntimeError("No pages extracted from PDF.")

        results = [None] * len(page_images)
        max_workers = min(2, len(page_images))

        log_progress(f"Toplam {len(page_images)} sayfa hazırlandı. OCR işlemi başlıyor...")

        with concurrent.futures.ThreadPoolExecutor(max_workers=max_workers) as executor:
            future_to_index = {
                executor.submit(ocr_page, img, idx + 1): idx
                for idx, img in enumerate(page_images)
            }
            for future in concurrent.futures.as_completed(future_to_index):
                idx = future_to_index[future]
                try:
                    results[idx] = future.result()
                    log_progress(f"Sayfa {idx + 1} OCR okuması bitti")
                except Exception as exc:
                    results[idx] = []
                    log_progress(f"Sayfa {idx + 1} OCR hatası: {str(exc)}")

        flat = []
        for page_lines in results:
            if page_lines:
                flat.extend(page_lines)
        return flat
    finally:
        shutil.rmtree(temp_dir, ignore_errors=True)


def process_text_to_lines(text: str) -> list:
    raw_lines = text.replace("\f", "\n").split("\n")
    processed_lines = []
    for line in raw_lines:
        line_strip = line.strip()
        if not line_strip:
            continue
        processed_lines.append(line_strip)
    return processed_lines


def main():
    parser = argparse.ArgumentParser(description="HBC Mutabakat PDF Barcode Extractor Engine")
    parser.add_argument("--pdf", required=True, help="Path to the PDF file")
    parser.add_argument("--mode", choices=["text", "ocr"], default="ocr", help="Extraction mode")
    parser.add_argument("--raw", action="store_true", help="Return raw text only without processing")

    args = parser.parse_args()

    if not os.path.exists(args.pdf):
        print(json.dumps({"success": False, "message": f"PDF file not found: {args.pdf}"}))
        sys.exit(1)

    start_time = time.time()
    try:
        if args.mode == "ocr":
            structured = extract_ocr_structured(args.pdf)

            if args.raw:
                print(json.dumps({
                    "success": True,
                    "raw_text": "\n".join(l["text"] for l in structured),
                    "elapsed_time": round(time.time() - start_time, 4)
                }, ensure_ascii=False))
                sys.exit(0)

            print(json.dumps({
                "success": True,
                "lines": [l["text"] for l in structured],
                "lines_meta": [{"conf": l["conf"], "alts": l["alts"]} for l in structured],
                "elapsed_time": round(time.time() - start_time, 4)
            }, ensure_ascii=False))
            sys.exit(0)

        text = extract_text_mode(args.pdf)

        if args.raw:
            print(json.dumps({
                "success": True,
                "raw_text": text,
                "elapsed_time": round(time.time() - start_time, 4)
            }, ensure_ascii=False))
            sys.exit(0)

        pdf_lines = process_text_to_lines(text)
        print(json.dumps({
            "success": True,
            "lines": pdf_lines,
            "elapsed_time": round(time.time() - start_time, 4)
        }, ensure_ascii=False))

    except Exception as e:
        print(json.dumps({
            "success": False,
            "message": str(e)
        }, ensure_ascii=False))
        sys.exit(1)


if __name__ == "__main__":
    main()
