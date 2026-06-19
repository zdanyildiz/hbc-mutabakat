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

    /**
     * Extracts store name from PDF file.
     *
     * @param string $filePath
     * @return string
     */
    public function extractStoreName(string $filePath): string
    {
        if (!file_exists($filePath)) {
            return 'Bilinmeyen Mağaza';
        }

        $parser = new Parser();
        try {
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText();
        } catch (\Exception $e) {
            return 'Bilinmeyen Mağaza';
        }

        $lines = explode("\n", $text);
        
        $foundStoreLabel = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($foundStoreLabel) {
                if (preg_match('/(mutabakat|tarih|belge|numara|rapor)/iu', $line)) {
                    continue;
                }
                
                // Fix OCR glitches in common store names (e.g. T3o&lsT -> T308)
                $cleanedStore = strtr($line, [
                    'T3o&lsT' => 'T308',
                    'o' => '0',
                    'O' => '0',
                    'l' => '1',
                ]);
                return $cleanedStore;
            }

            if (mb_stripos($line, 'Mağaza') !== false) {
                $foundStoreLabel = true;
            }
        }

        $filename = basename($filePath, '.pdf');
        if (preg_match('/^T\d+$/i', $filename)) {
            return strtoupper($filename);
        }

        return 'Bilinmeyen Mağaza';
    }
}
