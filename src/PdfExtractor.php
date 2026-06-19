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
        // Regex to find 16 to 20 digit numbers (Tema Takip No format)
        if (preg_match_all('/\b\d{16,20}\b/', $text, $matches)) {
            foreach ($matches[0] as $match) {
                $barcodes[] = $match;
            }
        }

        // Remove duplicates and re-index
        return array_values(array_unique($barcodes));
    }
}
