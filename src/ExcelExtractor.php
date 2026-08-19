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

        $exStart = microtime(true);
        \App\Logger::log("[ExcelExtractor] Dosya okuma başladı: " . basename($filePath));

        // Tüm hücre değerlerini doğrudan metin (string) olarak bağla. 
        // Bu sayede 18 haneli büyük sayılar float'a dönüşüp yuvarlanmaz veya scientific notation (1.63E+17) yüzünden yutulmaz.
        \PhpOffice\PhpSpreadsheet\Cell\Cell::setValueBinder(new \PhpOffice\PhpSpreadsheet\Cell\StringValueBinder());

        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
        } catch (\Exception $e) {
            \App\Logger::log("[ExcelExtractor] HATA: " . $e->getMessage());
            throw new \RuntimeException("Excel/CSV dosyası yüklenirken hata oluştu: " . $e->getMessage());
        }

        $barcodes = [];
        $rowIndex = 0;

        foreach ($worksheet->getRowIterator() as $rowIndex => $row) {
            if ($rowIndex === 1) {
                continue; // Skip header
            }

            $cell = $worksheet->getCell('A' . $rowIndex);
            $barcodeVal = $cell->getValue();
            $barcodeStr = '';
            if (is_scalar($barcodeVal)) {
                $barcodeStr = trim((string)$barcodeVal);
            } elseif ($barcodeVal instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                $barcodeStr = trim($barcodeVal->getPlainText());
            }

            if ($barcodeStr === '') {
                continue;
            }

            // Sadece sayısal kısmı temizleyelim
            $barcodeStrCleaned = preg_replace('/\D/', '', $barcodeStr);
            if ($barcodeStrCleaned !== null && strlen($barcodeStrCleaned) >= 5 && strlen($barcodeStrCleaned) <= 30) {
                $barcodes[] = $barcodeStrCleaned;
            }
        }

        $uniqueBarcodes = array_values(array_unique($barcodes));
        $elapsed = round(microtime(true) - $exStart, 4);
        \App\Logger::log("[ExcelExtractor] Tamamlandı - Süre: {$elapsed} saniye | Toplam Satır: {$rowIndex} | Benzersiz Barkod: " . count($uniqueBarcodes));

        return $uniqueBarcodes;
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

        // Tüm hücre değerlerini doğrudan metin (string) olarak bağla.
        // Bu sayede 18 haneli büyük sayılar float'a dönüşüp yuvarlanmaz veya scientific notation (1.63E+17) yüzünden yutulmaz.
        \PhpOffice\PhpSpreadsheet\Cell\Cell::setValueBinder(new \PhpOffice\PhpSpreadsheet\Cell\StringValueBinder());

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
            $barcodeVal = $barcodeCell->getValue();
            $barcodeStr = '';
            if (is_scalar($barcodeVal)) {
                $barcodeStr = trim((string)$barcodeVal);
            } elseif ($barcodeVal instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                $barcodeStr = trim($barcodeVal->getPlainText());
            }

            if ($barcodeStr === '') {
                continue;
            }

            // Sadece sayısal kısımları alalım
            $barcodeStrCleaned = preg_replace('/\D/', '', $barcodeStr);
            if ($barcodeStrCleaned === null || strlen($barcodeStrCleaned) < 5 || strlen($barcodeStrCleaned) > 30) {
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

    /**
     * Extracts detailed row information including barcode, invoice number, and store.
     *
     * @param string $filePath
     * @return array<int, array{row: int, barcode: ?string, customer_barcode: ?string, irsaliye_no: ?string, store: string}>
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function extractRowsDetails(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Excel/CSV dosyası bulunamadı: {$filePath}");
        }

        \PhpOffice\PhpSpreadsheet\Cell\Cell::setValueBinder(new \PhpOffice\PhpSpreadsheet\Cell\StringValueBinder());

        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
        } catch (\Exception $e) {
            throw new \RuntimeException("Excel/CSV dosyası yüklenirken hata oluştu: " . $e->getMessage());
        }

        $details = [];

        foreach ($worksheet->getRowIterator() as $rowIndex => $row) {
            if ($rowIndex === 1) {
                continue; // Skip header
            }

            $getVal = function (string $col) use ($worksheet, $rowIndex): string {
                $cell = $worksheet->getCell($col . $rowIndex);
                $val = $cell->getValue();
                if (is_scalar($val)) {
                    return trim((string)$val);
                }
                if ($val instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                    return trim($val->getPlainText());
                }
                return '';
            };

            $barcodeRaw = $getVal('A');
            $customerBarcodeRaw = $getVal('B');
            $irsaliyeRaw = $getVal('C');
            $storeRaw = $getVal('F');

            $cleanBarcode = preg_replace('/\D/', '', $barcodeRaw);
            if (!is_string($cleanBarcode) || strlen($cleanBarcode) < 5) {
                $cleanBarcode = null;
            }

            $cleanCustomerBarcode = preg_replace('/\D/', '', $customerBarcodeRaw);
            if (!is_string($cleanCustomerBarcode) || strlen($cleanCustomerBarcode) < 5) {
                $cleanCustomerBarcode = null;
            }

            $cleanIrsaliye = trim($irsaliyeRaw);
            if ($cleanIrsaliye === '' || $cleanIrsaliye === '-' || strlen($cleanIrsaliye) < 4) {
                $cleanIrsaliye = null;
            }

            if ($storeRaw === '') {
                $storeRaw = "[Mağaza Adı Belirtilmemiş (Satır {$rowIndex})]";
            }

            $details[] = [
                'row' => $rowIndex,
                'barcode' => $cleanBarcode,
                'customer_barcode' => $cleanCustomerBarcode,
                'irsaliye_no' => $cleanIrsaliye,
                'store' => $storeRaw,
            ];
        }

        return $details;
    }

    /**
     * Returns a map of barcode => clean invoice number.
     *
     * @param string $filePath
     * @return array<string, string>
     */
    public function extractBarcodeToInvoiceMap(string $filePath): array
    {
        $details = $this->extractRowsDetails($filePath);
        $map = [];
        foreach ($details as $d) {
            if ($d['barcode'] !== null && $d['irsaliye_no'] !== null) {
                $map[$d['barcode']] = $d['irsaliye_no'];
            }
        }
        return $map;
    }
}
