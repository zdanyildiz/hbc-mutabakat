#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
import sys
import argparse
import subprocess
import tempfile
import shutil
import time
import concurrent.futures

try:
    from PIL import Image, ImageEnhance, ImageFilter
    HAS_PILLOW = True
except ImportError:
    HAS_PILLOW = False


def preprocess_image_pillow(input_path: str, output_path: str) -> str:
    """Applies fast in-memory image enhancement using Pillow (PIL)."""
    try:
        with Image.open(input_path) as img:
            gray = img.convert('L')
            enhanced = ImageEnhance.Contrast(gray).enhance(1.5)
            sharpened = enhanced.filter(ImageFilter.SHARPEN)
            sharpened.save(output_path)
            return output_path
    except Exception:
        return input_path


def parse_tsv_output(tsv_data: str, page_num: int):
    """
    Parses Tesseract TSV output format:
    level	page_num	block_num	par_num	line_num	word_num	left	top	width	height	conf	text
    Returns (page_text, word_confidences_list)
    """
    lines_map = {}
    line_keys_order = []
    word_confidences = []

    lines = tsv_data.splitlines()
    if not lines:
        return "", []

    # Skip header
    header = lines[0].split('\t')
    conf_idx = 10
    text_idx = 11
    block_num_idx = 2
    par_num_idx = 3
    line_num_idx = 4

    if 'conf' in header:
        conf_idx = header.index('conf')
    if 'text' in header:
        text_idx = header.index('text')
    if 'block_num' in header:
        block_num_idx = header.index('block_num')
    if 'par_num' in header:
        par_num_idx = header.index('par_num')
    if 'line_num' in header:
        line_num_idx = header.index('line_num')

    for line in lines[1:]:
        parts = line.split('\t')
        if len(parts) <= max(conf_idx, text_idx):
            continue

        raw_text = parts[text_idx].strip()
        raw_conf = parts[conf_idx].strip()

        if not raw_text:
            continue

        try:
            conf_val = float(raw_conf)
        except ValueError:
            conf_val = -1.0

        b_num = parts[block_num_idx] if len(parts) > block_num_idx else '1'
        p_num = parts[par_num_idx] if len(parts) > par_num_idx else '1'
        l_num = parts[line_num_idx] if len(parts) > line_num_idx else '1'

        composite_key = f"{b_num}_{p_num}_{l_num}"

        if composite_key not in lines_map:
            lines_map[composite_key] = []
            line_keys_order.append(composite_key)
        lines_map[composite_key].append(raw_text)

        if conf_val >= 0:
            word_confidences.append({
                "text": raw_text,
                "conf": int(round(conf_val)),
                "page": page_num
            })

    # Reconstruct text preserving natural appearance order (block_num, par_num, line_num)
    page_text_lines = []
    for key in line_keys_order:
        line_str = " ".join(lines_map[key])
        if line_str:
            page_text_lines.append(line_str)

    return "\n".join(page_text_lines), word_confidences


def ocr_page(img_path: str, page_num: int) -> dict:
    try:
        custom_env = os.environ.copy()
        custom_env["OMP_THREAD_LIMIT"] = "1"

        img_dir = os.path.dirname(img_path)
        img_name = os.path.basename(img_path)
        clean_img_path = os.path.join(img_dir, "clean_" + img_name)

        if HAS_PILLOW:
            tess_input_path = preprocess_image_pillow(img_path, clean_img_path)
        else:
            # ImageMagick fallback if convert exists
            try:
                subprocess.run([
                    "convert", img_path,
                    "-colorspace", "gray",
                    "-level", "15%,85%",
                    "-sharpen", "0x1.5",
                    clean_img_path
                ], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                tess_input_path = clean_img_path
            except Exception:
                tess_input_path = img_path

        # Run Tesseract with TSV output format and -l eng (digit whitelist active)
        result = subprocess.run([
            "tesseract", tess_input_path, "stdout", "tsv",
            "--psm", "6",
            "-l", "eng",
            "-c", "tessedit_char_whitelist=0123456789"
        ], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, encoding="utf-8", timeout=30, env=custom_env)

        if clean_img_path and os.path.exists(clean_img_path):
            try:
                os.remove(clean_img_path)
            except OSError:
                pass

        page_text, word_confs = parse_tsv_output(result.stdout, page_num)
        return {
            "text": page_text,
            "word_confidences": word_confs
        }
    except Exception as e:
        sys.stderr.write(f"Tesseract error on page {page_num} ({img_path}): {str(e)}\n")
        return {"text": "", "word_confidences": []}


def extract_text_mode(pdf_path: str) -> str:
    try:
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
    except FileNotFoundError:
        raise RuntimeError("pdftotext executable is not installed or not found in PATH.")


def extract_ocr_mode(pdf_path: str, max_workers: int = 3) -> dict:
    temp_dir = tempfile.mkdtemp()
    try:
        # Use pdftoppm -png -gray directly to generate compressed grayscale PNGs
        subprocess.run([
            "pdftoppm", "-png", "-gray", "-r", "300",
            pdf_path, os.path.join(temp_dir, "page")
        ], check=True)

        page_images = sorted([
            os.path.join(temp_dir, f)
            for f in os.listdir(temp_dir)
            if f.startswith("page-") and (f.endswith(".png") or f.endswith(".pgm"))
        ])

        if not page_images:
            raise RuntimeError("No pages extracted from PDF.")

        workers = min(max_workers, len(page_images))
        full_text_parts = [None] * len(page_images)
        all_word_confidences = []

        sys.stderr.write(f"OCR_PROGRESS: Toplam {len(page_images)} sayfa görsele dönüştürüldü (Workers: {workers}). OCR işlemi başlıyor...\n")
        sys.stderr.flush()

        with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as executor:
            future_to_index = {
                executor.submit(ocr_page, img, idx + 1): idx
                for idx, img in enumerate(page_images)
            }

            for future in concurrent.futures.as_completed(future_to_index):
                idx = future_to_index[future]
                try:
                    res = future.result()
                    full_text_parts[idx] = res.get("text", "")
                    all_word_confidences.extend(res.get("word_confidences", []))
                    sys.stderr.write(f"OCR_PROGRESS: Sayfa {idx + 1} OCR okuması bitti\n")
                    sys.stderr.flush()
                except Exception as exc:
                    sys.stderr.write(f"OCR_PROGRESS: Sayfa {idx + 1} OCR hatası: {str(exc)}\n")
                    sys.stderr.flush()

        full_text = "\f".join([r for r in full_text_parts if r is not None])
        return {
            "text": full_text,
            "word_confidences": all_word_confidences
        }

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
    parser = argparse.ArgumentParser(description="HBC Mutabakat PDF Barcode Extractor Engine (TSV Enhanced)")
    parser.add_argument("--pdf", required=True, help="Path to the PDF file")
    parser.add_argument("--mode", choices=["text", "ocr"], default="ocr", help="Extraction mode")
    parser.add_argument("--raw", action="store_true", help="Return raw text only without processing")
    parser.add_argument("--workers", type=int, default=3, help="Max parallel OCR workers")

    args = parser.parse_args()

    if not os.path.exists(args.pdf):
        import json
        print(json.dumps({"success": False, "message": f"PDF file not found: {args.pdf}"}))
        sys.exit(1)

    start_time = time.time()
    try:
        word_confidences = []
        if args.mode == "ocr":
            ocr_result = extract_ocr_mode(args.pdf, max_workers=args.workers)
            text = ocr_result["text"]
            word_confidences = ocr_result["word_confidences"]
        else:
            text = extract_text_mode(args.pdf)

        if args.raw:
            import json
            print(json.dumps({
                "success": True,
                "raw_text": text,
                "word_confidences": word_confidences,
                "elapsed_time": round(time.time() - start_time, 4)
            }, ensure_ascii=False))
            sys.exit(0)

        pdf_lines = process_text_to_lines(text)
        elapsed = time.time() - start_time

        import json
        print(json.dumps({
            "success": True,
            "lines": pdf_lines,
            "word_confidences": word_confidences,
            "elapsed_time": round(elapsed, 4)
        }, ensure_ascii=False))

    except Exception as e:
        import json
        print(json.dumps({
            "success": False,
            "message": str(e)
        }, ensure_ascii=False))
        sys.exit(1)


if __name__ == "__main__":
    main()
