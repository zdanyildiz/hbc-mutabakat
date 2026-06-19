<?php

declare(strict_types=1);

namespace App;

use Smalot\PdfParser\Parser;

class PdfExtractor
{
    /**
     * Extracts barcode/tracking numbers from a PDF file.
     *
     * @param string $filePath
     * @return array<string>
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function extract(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("PDF dosyası bulunamadı: {$filePath}");
        }

        $parser = new Parser();
        try {
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText();
        } catch (\Exception $e) {
            throw new \RuntimeException("PDF dosyası ayrıştırılamadı: " . $e->getMessage());
        }

        $barcodes = [];
        $lines = explode("\n", $text);

        // Common OCR character mappings for scanned documents
        $ocrMap = [
            'l' => '1',
            'ı' => '1',
            'I' => '1',
            'i' => '1',
            '!' => '1',
            '[' => '1',
            ']' => '1',
            'B' => '8',
            'M' => '0',
            'O' => '0',
            'o' => '0',
            'E' => '8',
            'S' => '5',
        ];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Split line by whitespace/tabs to process word by word
            $words = preg_split('/[\s\t]+/', $line);
            if ($words === false) {
                continue;
            }

            foreach ($words as $word) {
                $word = trim($word);
                if ($word === '') {
                    continue;
                }

                // 1) Apply OCR character mapping
                $converted = strtr($word, $ocrMap);

                // 2) Remove any non-digit characters
                $cleaned = preg_replace('/\D/', '', $converted);

                // 3) Validate if it fits tracking barcode lengths (16 to 20 digits)
                if ($cleaned !== null && strlen($cleaned) >= 16 && strlen($cleaned) <= 20) {
                    $barcodes[] = $cleaned;
                }
            }
        }

        // Remove duplicates and re-index
        return array_values(array_unique($barcodes));
    }
}
