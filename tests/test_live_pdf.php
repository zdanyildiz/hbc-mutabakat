<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\PdfExtractor;

\App\Env::load(dirname(__DIR__));

$pdfPath = $argv[1] ?? (file_exists(dirname(__DIR__) . '/T285.pdf') ? dirname(__DIR__) . '/T285.pdf' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\T285.pdf');

echo "=== CANLI TEST: Google Document AI Ayrıştırması ===\n";
echo "Dosya: {$pdfPath}\n";

$extractor = new PdfExtractor();
$extractor->setUseOcr(true);

$startTime = microtime(true);
try {
    $lines = $extractor->extract($pdfPath);
    $manifestRows = $extractor->getManifestRows($pdfPath);
    $storeName = $extractor->extractStoreName($pdfPath, 'T285.pdf');
    $elapsed = round(microtime(true) - $startTime, 4);

    echo "\n>>> BAŞARILI! <<<\n";
    echo "Süre: {$elapsed} saniye\n";
    echo "Mağaza Adı: {$storeName}\n";
    echo "Çıkarılan Toplam Satır Sayısı: " . count($lines) . "\n";
    echo "Çıkarılan Yapısal Tablo Satır Sayısı: " . count($manifestRows) . "\n";

    echo "\n--- İLK 10 YAPISAL TABLO SATIRI ---\n";
    foreach (array_slice($manifestRows, 0, 10) as $i => $row) {
        printf(
            "[%02d] Barkod: %-18s | Yedek Barkod: %-18s | Depo/Mağaza: %-8s | İrsaliye: %s\n",
            $i + 1,
            $row['barcode'] ?? '-',
            $row['barcode_fallback'] ?? '-',
            $row['store'] ?? '-',
            $row['irsaliye_no'] ?? '-'
        );
    }

    echo "\n--- TÜM ÇIKARILAN TEKİL BARKODLAR (" . count(array_filter(array_column($manifestRows, 'barcode'))) . " adet) ---\n";
    $barcodes = array_values(array_unique(array_filter(array_column($manifestRows, 'barcode'))));
    print_r(array_slice($barcodes, 0, 20));

} catch (\Throwable $e) {
    echo "\n>>> HATA OLUŞTU! <<<\n";
    echo "[" . get_class($e) . "] " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
