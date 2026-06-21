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
            "-c", "tessedit_char_whitelist=0123456789LIM- "
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
            "pdftoppm", "-png", "-r", "150",
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

def process_line_for_barcodes(line: str, barcode_to_original: dict):
    # Split line by 2+ spaces or tabs to isolate table columns
    columns = re.split(r'(?:\s{2,}|\t)', line)
    candidates = []

    for col in columns:
        col_clean = col.strip()
        if not col_clean:
            continue
        
        # Remove internal spaces or hyphens in a single column (OCR splitting fix)
        word = re.sub(r'[\s-]+', '', col_clean)
        
        # Apply OCR MAP to see if it becomes a valid barcode
        conv = apply_ocr_map(word)
        clean = re.sub(r'\D', '', conv)
        
        # Smart sequence number trimming:
        length = len(clean)
        if length > 18:
            for target_len in [18, 17, 16]:
                if length >= target_len:
                    suffix = clean[-target_len:]
                    if re.match(r'^(?:1|6|7)', suffix):
                        clean = suffix
                        break
        
        final_len = len(clean)
        if 16 <= final_len <= 20:
            # Count letters in original word to check for pure digits
            letters_count = sum(1 for c in word if c.isalpha())
            candidates.append({
                'original': col_clean,
                'word': word,
                'converted': clean,
                'letters_count': letters_count,
                'is_pure_digits': word.isdigit()
            })

    if not candidates:
        return []

    # Group similar candidates (Levenshtein distance <= 2)
    resolved = []
    for cand in candidates:
        is_merged = False
        for i, res in enumerate(resolved):
            if levenshtein(cand['converted'], res['converted']) <= 2:
                # Compare and keep the one with fewer letters (more digits)
                if cand['letters_count'] < res['letters_count']:
                    resolved[i] = cand
                is_merged = True
                break
        if not is_merged:
            resolved.append(cand)

    line_barcodes = []
    for res in resolved:
        line_barcodes.append(res['converted'])
        barcode_to_original[res['converted']] = res['original']

    return line_barcodes

def process_text_to_barcodes(text: str):
    # Process line by line on original text
    lines = [line.strip() for line in text.split("\n")]
    preprocessed_lines = []

    # 1. Preprocess split lines (joining wrapping lines)
    i = 0
    while i < len(lines):
        line = lines[i]
        if not line:
            i += 1
            continue

        conv_line = apply_ocr_map(line)
        digits_in_line = re.sub(r'\D', '', conv_line)
        if 11 <= len(digits_in_line) <= 15:
            if i + 1 < len(lines):
                next_line = lines[i + 1]
                conv_next = apply_ocr_map(next_line)
                next_digits = re.sub(r'\D', '', conv_next)
                if next_digits and len(next_line) <= 5:
                    line = line + " " + next_line
                    i += 1
        
        preprocessed_lines.append(line)
        i += 1

    barcodes = []
    barcode_to_original = {}

    # 2. Extract barcodes line by line
    for line in preprocessed_lines:
        line_barcodes = process_line_for_barcodes(line, barcode_to_original)
        barcodes.extend(line_barcodes)

    unique_barcodes = list(set(barcodes))
    return unique_barcodes, barcode_to_original

def main():
    parser = argparse.ArgumentParser(description="HBC Mutabakat PDF Barcode Extractor Engine")
    parser.add_argument("--pdf", required=True, help="Path to the PDF file")
    parser.add_argument("--mode", choices=["text", "ocr"], default="text", help="Extraction mode")
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

        barcodes, barcode_to_original = process_text_to_barcodes(text)
        elapsed = time.time() - start_time

        import json
        print(json.dumps({
            "success": True,
            "barcodes": barcodes,
            "barcode_to_original": barcode_to_original,
            "mismatches": [], # Disabled mismatches completely as per user request
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
