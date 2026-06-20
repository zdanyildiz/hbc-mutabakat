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

        foreach ($worksheet->getRowIterator() as $rowIndex => $row) {
            if ($rowIndex === 1) {
                continue; // Skip header
            }

            $cell = $worksheet->getCell('A' . $rowIndex);
            $barcodeStr = trim($cell->getFormattedValue());
            if ($barcodeStr === '') {
                $barcodeVal = $cell->getValue();
                if (is_scalar($barcodeVal)) {
                    $barcodeStr = trim((string)$barcodeVal);
                } elseif ($barcodeVal instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                    $barcodeStr = trim($barcodeVal->getPlainText());
                }
            }

            if ($barcodeStr === '') {
                continue;
            }

            // Sadece sayısal kısmı temizleyelim
            $barcodeStrCleaned = preg_replace('/\D/', '', $barcodeStr);
            if ($barcodeStrCleaned !== null && strlen($barcodeStrCleaned) >= 16 && strlen($barcodeStrCleaned) <= 20) {
                $barcodes[] = $barcodeStrCleaned;
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

            $barcodeCell = $worksheet->getCell('A' . $rowIndex);
            // Büyük rakamların bozulmaması için getFormattedValue() öncelikli
            $barcodeStr = trim($barcodeCell->getFormattedValue());
            if ($barcodeStr === '') {
                $barcodeVal = $barcodeCell->getValue();
                if (is_scalar($barcodeVal)) {
                    $barcodeStr = trim((string)$barcodeVal);
                } elseif ($barcodeVal instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                    $barcodeStr = trim($barcodeVal->getPlainText());
                }
            }

            if ($barcodeStr === '') {
                continue;
            }

            // Sadece sayısal kısımları alalım (böylece .0 vb. temizlenir)
            $barcodeStrCleaned = preg_replace('/\D/', '', $barcodeStr);
            if ($barcodeStrCleaned === null || strlen($barcodeStrCleaned) < 16 || strlen($barcodeStrCleaned) > 20) {
                continue;
            }

            $storeCell = $worksheet->getCell('F' . $rowIndex);
            $storeVal = $storeCell->getValue();
            $storeStr = '';
            if ($storeVal !== null && is_scalar($storeVal)) {
                $storeStr = trim((string)$storeVal);
            } elseif ($storeVal instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                $storeStr = trim($storeVal->getPlainText());
            }

            if ($storeStr === '') {
                $storeStr = "[Mağaza Adı Belirtilmemiş (Satır {$rowIndex})]";
            }

            $map[$barcodeStrCleaned] = $storeStr;
        }

        return $map;
    }
}
