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

    /**
     * Extracts barcode to store name mapping from an Excel or CSV file.
     *
     * @param string $filePath
     * @return array<string, string>
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function extractMap(string $filePath): array
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

        $map = [];

        foreach ($worksheet->getRowIterator() as $rowIndex => $row) {
            if ($rowIndex === 1) {
                continue; // Skip header
            }

            // Column A -> Barcode
            $barcodeCell = $worksheet->getCell('A' . $rowIndex);
            $barcodeVal = $barcodeCell->getValue();
            if ($barcodeVal === null || $barcodeVal === '') {
                continue;
            }

            $barcodeStr = '';
            if (is_scalar($barcodeVal)) {
                $barcodeStr = trim((string)$barcodeVal);
            } elseif ($barcodeVal instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                $barcodeStr = trim($barcodeVal->getPlainText());
            }

            if ($barcodeStr === '') {
                continue;
            }

            // Column F -> Store Name
            $storeCell = $worksheet->getCell('F' . $rowIndex);
            $storeVal = $storeCell->getValue();
            $storeStr = '';
            if ($storeVal !== null && is_scalar($storeVal)) {
                $storeStr = trim((string)$storeVal);
            } elseif ($storeVal instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                $storeStr = trim($storeVal->getPlainText());
            }

            if (preg_match('/^\d{16,20}$/', $barcodeStr)) {
                $map[$barcodeStr] = $storeStr !== '' ? $storeStr : 'Bilinmeyen Mağaza';
            }
        }

        return $map;
    }
}
