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
    # Python translate natively handles multi-byte/unicode characters
    trans_table = str.maketrans(OCR_MAP)
    return text.translate(trans_table)

def ocr_page(img_path: str) -> str:
    """Runs Tesseract OCR on a single page image with a restricted character whitelist."""
    try:
        # psm 6: Assume a single uniform block of text.
        # tessedit_char_whitelist restricts recognition to digits and barcode symbols for speed/accuracy.
        result = subprocess.run([
            "tesseract", img_path, "stdout",
            "--psm", "6",
            "-c", "tessedit_char_whitelist=0123456789LIM- "
        ], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, encoding="utf-8")
        return result.stdout
    except Exception as e:
        sys.stderr.write(f"Tesseract error on {img_path}: {str(e)}\n")
        return ""

def extract_text_mode(pdf_path: str) -> str:
    """Extracts text using Poppler's pdftotext CLI."""
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
    """Extracts text by converting PDF pages to images and running parallel OCR."""
    temp_dir = tempfile.mkdtemp()
    try:
        # Convert PDF pages to PNG using pdftoppm (high performance)
        # Resolution 150 DPI is a sweet spot for both speed and Tesseract accuracy
        subprocess.run([
            "pdftoppm", "-png", "-r", "150",
            pdf_path, os.path.join(temp_dir, "page")
        ], check=True)

        # Get all page images generated
        page_images = sorted([
            os.path.join(temp_dir, f)
            for f in os.listdir(temp_dir)
            if f.startswith("page-") and f.endswith(".png")
        ])

        if not page_images:
            raise RuntimeError("No pages extracted from PDF.")

        # Run OCR in parallel across CPU cores
        full_text_parts = []
        # Ryzen 9 has high core counts, we cap at 4 parallel processes or threads to keep system stable
        max_workers = min(4, len(page_images))
        with concurrent.futures.ThreadPoolExecutor(max_workers=max_workers) as executor:
            results = executor.map(ocr_page, page_images)
            for res in results:
                full_text_parts.append(res)

        return "\n".join(full_text_parts)

    finally:
        # Clean up all temp images
        shutil.rmtree(temp_dir, ignore_errors=True)

def process_text_to_barcodes(text: str):
    """Processes extracted text, cleans character encoding, merges wrapped lines, and extracts barcodes."""
    # 1. Apply global character mapping
    converted_text = apply_ocr_map(text)

    # 2. Split into lines
    lines = [line.strip() for line in converted_text.split("\n")]
    preprocessed_lines = []

    # 3. Preprocess split lines (handling line wrapping / column overflow)
    i = 0
    while i < len(lines):
        line = lines[i]
        if not line:
            i += 1
            continue

        # Count digits in current line
        digits_in_line = re.sub(r'\D', '', line)
        if 11 <= len(digits_in_line) <= 15:
            # Check if next line exists and is a short numeric suffix
            if i + 1 < len(lines):
                next_line = lines[i + 1]
                next_digits = re.sub(r'\D', '', next_line)
                if next_digits and len(next_line) <= 5:
                    line = line + " " + next_line
                    i += 1  # Skip the next line as it is merged
        
        preprocessed_lines.append(line)
        i += 1

    barcodes = []
    barcode_to_original = {}
    mismatches = []

    # 4. Extract barcodes
    for line_idx, line in enumerate(preprocessed_lines):
        # Match candidate blocks of digits, spaces, and hyphens starting and ending with a digit
        matches = re.findall(r'\b[0-9](?:[0-9\s-]*[0-9])?\b', line)
        
        line_barcodes = []
        line_words = []

        for match in matches:
            cleaned = re.sub(r'\D', '', match)
            if not cleaned:
                continue

            # Smart sequence number trimming:
            # If the digits block is 19 or 20 digits, and its suffix of 16-18 digits starts with
            # a valid barcode prefix (1, 6, or 7), we trim the leading sequence numbers.
            length = len(cleaned)
            if length > 18:
                for target_len in [18, 17, 16]:
                    if length >= target_len:
                        suffix = cleaned[-target_len:]
                        if re.match(r'^(?:1|6|7)', suffix):
                            cleaned = suffix
                            break

            final_len = len(cleaned)
            if 16 <= final_len <= 20:
                line_barcodes.append(cleaned)
                line_words.append(match)

        # Remove duplicate barcodes on the same line
        unique_line_barcodes = list(set(line_barcodes))
        
        if len(unique_line_barcodes) == 1:
            barcode = unique_line_barcodes[0]
            barcodes.append(barcode)
            # Map barcode to its original layout word
            for idx, cb in enumerate(line_barcodes):
                if cb == barcode:
                    barcode_to_original[barcode] = line_words[idx]
                    break
        elif len(unique_line_barcodes) > 1:
            # Multiple different barcodes on a single line represents a mismatch/discrepancy
            mismatches.append({
                "line_number": line_idx + 1,
                "line_text": line,
                "detected_barcodes": unique_line_barcodes
            })
            for barcode in unique_line_barcodes:
                barcodes.append(barcode)
                for idx, cb in enumerate(line_barcodes):
                    if cb == barcode:
                        barcode_to_original[barcode] = line_words[idx]
                        break

    unique_barcodes = list(set(barcodes))
    return unique_barcodes, barcode_to_original, mismatches

def main():
    parser = argparse.ArgumentParser(description="HBC Mutabakat PDF Barcode Extractor Engine")
    parser.add_argument("--pdf", required=True, help="Path to the PDF file")
    parser.add_argument("--mode", choices=["text", "ocr"], default="text", help="Extraction mode")

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

        barcodes, barcode_to_original, mismatches = process_text_to_barcodes(text)
        elapsed = time.time() - start_time

        import json
        print(json.dumps({
            "success": True,
            "barcodes": barcodes,
            "barcode_to_original": barcode_to_original,
            "mismatches": mismatches,
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
