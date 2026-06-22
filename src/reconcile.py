#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
import sys
import re
import argparse
import subprocess
import tempfile
import shutil
import time
import concurrent.futures

# Character mapping table to fix OCR and font encoding issues
OCR_MAP = {
    'l': '1', 'ı': '1', 'I': '1', 'i': '1', '!': '1', '[': '1', ']': '3',
    'B': '8', 'M': '0', 'O': '0', 'o': '0', 'E': '8', 'S': '5', 's': '5', 'ü': '4',
    't': '1', '|': '1', '}': '1', '{': '8', 'j': '3', 'J': '3'
}

def apply_ocr_map(text: str) -> str:
    trans_table = str.maketrans(OCR_MAP)
    return text.translate(trans_table)

def levenshtein(s1: str, s2: str) -> int:
    if len(s1) < len(s2):
        return levenshtein(s2, s1)
    if len(s2) == 0:
        return len(s1)
    previous_row = range(len(s2) + 1)
    for i, c1 in enumerate(s1):
        current_row = [i + 1]
        for j, c2 in enumerate(s2):
            insertions = previous_row[j + 1] + 1
            deletions = current_row[j] + 1
            substitutions = previous_row[j] + (c1 != c2)
            current_row.append(min(insertions, deletions, substitutions))
        previous_row = current_row
    return previous_row[-1]

def ocr_page(img_path: str) -> str:
    try:
        import os
        custom_env = os.environ.copy()
        custom_env["OMP_THREAD_LIMIT"] = "1"
        result = subprocess.run([
            "tesseract", img_path, "stdout",
            "--psm", "6",
            "-l", "tur+eng"
        ], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, encoding="utf-8", timeout=30, env=custom_env)
        return result.stdout
    except Exception as e:
        sys.stderr.write(f"Tesseract error on {img_path}: {str(e)}\n")
        return ""

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

def extract_ocr_mode(pdf_path: str) -> str:
    temp_dir = tempfile.mkdtemp()
    try:
        subprocess.run([
            "pdftoppm", "-png", "-r", "300",
            pdf_path, os.path.join(temp_dir, "page")
        ], check=True)

        page_images = sorted([
            os.path.join(temp_dir, f)
            for f in os.listdir(temp_dir)
            if f.startswith("page-") and f.endswith(".png")
        ])

        if not page_images:
            raise RuntimeError("No pages extracted from PDF.")

        full_text_parts = [None] * len(page_images)
        max_workers = min(2, len(page_images))

        sys.stderr.write(f"OCR_PROGRESS: Toplam {len(page_images)} sayfa görsele dönüştürüldü. OCR işlemi başlıyor...\n")
        sys.stderr.flush()

        with concurrent.futures.ThreadPoolExecutor(max_workers=max_workers) as executor:
            future_to_index = {
                executor.submit(ocr_page, img): idx 
                for idx, img in enumerate(page_images)
            }
            
            for future in concurrent.futures.as_completed(future_to_index):
                idx = future_to_index[future]
                try:
                    res = future.result()
                    full_text_parts[idx] = res
                    sys.stderr.write(f"OCR_PROGRESS: Sayfa {idx + 1} OCR okuması bitti\n")
                    sys.stderr.flush()
                except Exception as exc:
                    sys.stderr.write(f"OCR_PROGRESS: Sayfa {idx + 1} OCR hatası: {str(exc)}\n")
                    sys.stderr.flush()

        return "\f".join([r for r in full_text_parts if r is not None])

    finally:
        shutil.rmtree(temp_dir, ignore_errors=True)

def process_text_to_lines(text: str) -> list:
    raw_lines = text.replace("\f", "\n").split("\n")
    processed_lines = []
    
    for line in raw_lines:
        line_strip = line.strip()
        if not line_strip:
            continue
            
        # Boşlukları temizle (tüm satırda)
        clean_line = re.sub(r'\s+', '', line_strip)
        
        # 18 karakterden küçükse es geç
        if len(clean_line) < 18:
            continue
            
        processed_lines.append(clean_line)
        
    return processed_lines

def main():
    parser = argparse.ArgumentParser(description="HBC Mutabakat PDF Barcode Extractor Engine")
    parser.add_argument("--pdf", required=True, help="Path to the PDF file")
    parser.add_argument("--mode", choices=["text", "ocr"], default="ocr", help="Extraction mode")
    parser.add_argument("--raw", action="store_true", help="Return raw text only without processing")

    args = parser.parse_args()

    if not os.path.exists(args.pdf):
        print(f'{{"success": false, "message": "PDF file not found: {args.pdf}"}}')
        sys.exit(1)

    start_time = time.time()
    try:
        if args.mode == "ocr":
            text = extract_ocr_mode(args.pdf)
        else:
            text = extract_text_mode(args.pdf)

        if args.raw:
            import json
            print(json.dumps({
                "success": True,
                "raw_text": text,
                "elapsed_time": round(time.time() - start_time, 4)
            }, ensure_ascii=False))
            sys.exit(0)

        pdf_lines = process_text_to_lines(text)
        elapsed = time.time() - start_time

        import json
        print(json.dumps({
            "success": True,
            "lines": pdf_lines,
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
