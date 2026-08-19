<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
if (class_exists('\\App\\Env')) {
    \App\Env::load(dirname(__DIR__));
}

$excelPath = file_exists(dirname(__DIR__) . '/34AIA502.xlsx') ? dirname(__DIR__) . '/34AIA502.xlsx' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\34AIA502.xlsx';
$pdfPaths = [
    file_exists(dirname(__DIR__) . '/T285.pdf') ? dirname(__DIR__) . '/T285.pdf' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\T285.pdf',
    file_exists(dirname(__DIR__) . '/T3048.pdf') ? dirname(__DIR__) . '/T3048.pdf' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\T3048.pdf',
    file_exists(dirname(__DIR__) . '/T373.pdf') ? dirname(__DIR__) . '/T373.pdf' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\T373.pdf',
    file_exists(dirname(__DIR__) . '/T649.pdf') ? dirname(__DIR__) . '/T649.pdf' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\T649.pdf',
];

echo "=== YEREL MODEL + İRSALİYE EŞLEŞTİRME TESTİ ===\n";

$excelExtractor = new \App\ExcelExtractor();
$pdfExtractor = new \App\PdfExtractor();
$pdfExtractor->setUseOcr(true);

$reconciler = new \App\Reconciler($excelExtractor, $pdfExtractor);

$start = microtime(true);
$result = $reconciler->reconcile($excelPath, $pdfPaths, '1');
$elapsed = round(microtime(true) - $start, 4);

echo "\n>>> MUTABAKAT SONUÇLARI <<<\n";
echo "Toplam Süre: {$elapsed} sn\n";
echo "Terminal (Excel) Barkod: " . count($result->terminalBarcodes) . "\n";
echo "Eşleşen: " . count($result->matched) . "\n";
echo "Eksik: " . count($result->missingInStore) . "\n";
echo "Fazla: " . count($result->extraInStore) . "\n";
echo "Şüpheli (Fuzzy): " . count($result->suspectedMatches) . "\n";
