<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
\App\Env::load(dirname(__DIR__));

$excelPath = dirname(__DIR__) . '/34AIA502.xlsx';
$pdfPaths = [
    dirname(__DIR__) . '/T285.pdf',
    dirname(__DIR__) . '/T3048.pdf',
    dirname(__DIR__) . '/T373.pdf',
    dirname(__DIR__) . '/T649.pdf',
];

echo "=== TAM MUTABAKAT TESTİ (Google Document AI) ===\n";
echo "Excel: {$excelPath}\n";
echo "PDF Dosyaları: " . implode(', ', array_map('basename', $pdfPaths)) . "\n\n";

$excelExtractor = new \App\ExcelExtractor();
$pdfExtractor = new \App\PdfExtractor();
$pdfExtractor->setUseOcr(true);

$reconciler = new \App\Reconciler($excelExtractor, $pdfExtractor);

$start = microtime(true);
$result = $reconciler->reconcile($excelPath, $pdfPaths, '1');
$elapsed = round(microtime(true) - $start, 4);

echo "\n>>> SONUÇLAR <<<\n";
echo "Toplam Süre: {$elapsed} saniye\n";
echo "Terminal (Excel) Barkod Sayısı: " . count($result->terminalBarcodes) . "\n";
echo "Mağaza (PDF) Barkod Sayısı: " . count($result->storeBarcodes) . "\n";
echo "Tam Eşleşen: " . count($result->matched) . "\n";
echo "Eksik (Mağazada Olmayan): " . count($result->missingInStore) . "\n";
echo "Fazla (Terminalde Olmayan): " . count($result->extraInStore) . "\n";
echo "Şüpheli Eşleşme: " . count($result->suspectedMatches) . "\n";
