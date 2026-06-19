<?php

declare(strict_types=1);

namespace App;

use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelExtractor
{
    /**
     * Extracts barcode/tracking numbers from an Excel or CSV file.
     *
     * @param string $filePath
     * @return array<string>
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function extract(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Excel/CSV dosyası bulunamadı: {$filePath}");
        }

        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
        } catch (\Exception $e) {
            throw new \RuntimeException("Excel/CSV dosyası yüklenirken hata oluştu: " . $e->getMessage());
        }

        $barcodes = [];

        foreach ($worksheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false); // Loop all cells
            
            foreach ($cellIterator as $cell) {
                $value = $cell->getValue();
                if ($value === null || $value === '') {
                    continue;
                }

                // Convert formulas or rich text to string representation
                $valStr = '';
                if (is_scalar($value)) {
                    $valStr = trim((string)$value);
                } elseif ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                    $valStr = trim($value->getPlainText());
                }

                if ($valStr === '') {
                    continue;
                }

                // Use the same regex pattern to extract potential 16-20 digit tracking numbers
                if (preg_match_all('/\b\d{16,20}\b/', $valStr, $matches)) {
                    foreach ($matches[0] as $match) {
                        $barcodes[] = $match;
                    }
                }
            }
        }

        return array_values(array_unique($barcodes));
    }
}
