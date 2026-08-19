<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
\App\Env::load(dirname(__DIR__));

$excelPath = file_exists(dirname(__DIR__) . '/34AIA502.xlsx') ? dirname(__DIR__) . '/34AIA502.xlsx' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\34AIA502.xlsx';
$t649Pdf = file_exists(dirname(__DIR__) . '/T649.pdf') ? dirname(__DIR__) . '/T649.pdf' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\T649.pdf';

$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($excelPath);
$rows = $spreadsheet->getActiveSheet()->toArray();

$t649ExcelBarcodes = [];
foreach (array_slice($rows, 1) as $r) {
    if (str_contains($r[5] ?? '', 'T649')) {
        foreach ($r as $val) {
            $digits = preg_replace('/\D/', '', trim((string)$val));
            if (strlen($digits) >= 15 && strlen($digits) <= 20) {
                $t649ExcelBarcodes[$digits] = [
                    'irsaliye' => $r[2] ?? '',
                    'desi' => $r[3] ?? ''
                ];
                break;
            }
        }
    }
}

echo "T649 Excel'deki Toplam Barkod: " . count($t649ExcelBarcodes) . "\n";

$extractor = new \App\PdfExtractor();
$extractor->setUseOcr(true);
$pdfLines = $extractor->extract($t649Pdf);
$manifestRows = $extractor->getManifestRows($t649Pdf);

echo "T649.pdf Çıkarılan Satır Sayısı: " . count($pdfLines) . "\n";
echo "T649.pdf Çıkarılan Yapısal Tablo Satır Sayısı: " . count($manifestRows) . "\n";

$pdfBarcodes = [];
foreach ($manifestRows as $mr) {
    if ($mr['barcode']) $pdfBarcodes[$mr['barcode']] = true;
    if ($mr['barcode_fallback']) $pdfBarcodes[$mr['barcode_fallback']] = true;
}
foreach ($pdfLines as $line) {
    $words = preg_split('/\s+/', $line);
    foreach ($words ?: [] as $w) {
        $d = preg_replace('/\D/', '', $w);
        if (strlen($d) >= 15 && strlen($d) <= 20) {
            $pdfBarcodes[$d] = true;
        }
    }
}

echo "T649.pdf'te Bulunan Toplam Tekil Barkod: " . count($pdfBarcodes) . "\n\n";

$missingInT649 = [];
foreach ($t649ExcelBarcodes as $b => $info) {
    if (!isset($pdfBarcodes[$b])) {
        $missingInT649[$b] = $info;
    }
}

echo "T649 Eksik Kalan Barkod Sayısı: " . count($missingInT649) . "\n";
echo "--- EKSİK KALAN BARKODLAR VE İRSALİYELERİ ---\n";
foreach (array_slice($missingInT649, 0, 15) as $b => $info) {
    echo "Barkod: {$b} | İrsaliye: {$info['irsaliye']} | Desi: {$info['desi']}\n";
}
