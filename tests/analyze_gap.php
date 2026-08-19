<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
\App\Env::load(dirname(__DIR__));

$excelPath = file_exists(dirname(__DIR__) . '/34AIA502.xlsx') ? dirname(__DIR__) . '/34AIA502.xlsx' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\34AIA502.xlsx';
$pdfPaths = [
    file_exists(dirname(__DIR__) . '/T285.pdf') ? dirname(__DIR__) . '/T285.pdf' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\T285.pdf',
    file_exists(dirname(__DIR__) . '/T3048.pdf') ? dirname(__DIR__) . '/T3048.pdf' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\T3048.pdf',
    file_exists(dirname(__DIR__) . '/T373.pdf') ? dirname(__DIR__) . '/T373.pdf' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\T373.pdf',
    file_exists(dirname(__DIR__) . '/T649.pdf') ? dirname(__DIR__) . '/T649.pdf' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\T649.pdf',
];

echo "=== DERİN MUTABAKAT BOŞLUK (GAP) ANALİZİ ===\n";

// 1. Excel'i oku
$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($excelPath);
$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray();

$header = $rows[0] ?? [];
echo "Excel Başlıklar: " . implode(' | ', $header) . "\n\n";

$excelRows = [];
$excelBarcodes = [];
$excelStoreCounts = [];

foreach (array_slice($rows, 1) as $idx => $r) {
    // Barkod sutununu bulalim
    $barcode = null;
    $store = null;
    $irsaliye = null;

    foreach ($r as $colIdx => $val) {
        $valStr = trim((string)$val);
        $digits = preg_replace('/\D/', '', $valStr);
        if (is_string($digits) && strlen($digits) >= 15 && strlen($digits) <= 20) {
            $barcode = $digits;
        }
        if (preg_match('/^T\d+/i', $valStr)) {
            $store = strtoupper($valStr);
        }
    }

    if ($barcode !== null) {
        $excelBarcodes[$barcode] = [
            'row' => $idx + 2,
            'data' => $r,
            'store' => $store ?? 'Bilinmeyen'
        ];
        $storeKey = $store ?? 'Bilinmeyen';
        $excelStoreCounts[$storeKey] = ($excelStoreCounts[$storeKey] ?? 0) + 1;
    }
}

echo "Toplam Excel Barkod: " . count($excelBarcodes) . "\n";
echo "Excel'deki Mağaza/Depo Dağılımı:\n";
print_r($excelStoreCounts);

echo "\n--- BİLİNMEYEN / DİĞER MAĞAZALARA AİT İLK 15 SATIR ---\n";
$unknownCount = 0;
foreach ($excelBarcodes as $b => $info) {
    if ($info['store'] === 'Bilinmeyen' || str_contains($info['store'], 'T645') || str_contains($info['store'], 'T375')) {
        echo "Satır {$info['row']} | Barkod: {$b} | Sütunlar: " . implode(' | ', $info['data']) . "\n";
        $unknownCount++;
        if ($unknownCount >= 15) {
            break;
        }
    }
}

// 2. Yüklenen PDF'lerin Mağazaları
echo "\nYüklenen 4 PDF Dosyası:\n";
foreach ($pdfPaths as $p) {
    echo "- " . basename($p) . " (" . round(filesize($p) / 1024 / 1024, 2) . " MB)\n";
}
