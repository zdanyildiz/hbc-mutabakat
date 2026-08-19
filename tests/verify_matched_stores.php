<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
\App\Env::load(dirname(__DIR__));

$excelPath = file_exists(dirname(__DIR__) . '/34AIA502.xlsx') ? dirname(__DIR__) . '/34AIA502.xlsx' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\34AIA502.xlsx';
$pdfPaths = [
    'T285' => file_exists(dirname(__DIR__) . '/T285.pdf') ? dirname(__DIR__) . '/T285.pdf' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\T285.pdf',
    'T3048' => file_exists(dirname(__DIR__) . '/T3048.pdf') ? dirname(__DIR__) . '/T3048.pdf' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\T3048.pdf',
    'T373' => file_exists(dirname(__DIR__) . '/T373.pdf') ? dirname(__DIR__) . '/T373.pdf' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\T373.pdf',
    'T649' => file_exists(dirname(__DIR__) . '/T649.pdf') ? dirname(__DIR__) . '/T649.pdf' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\T649.pdf',
];

echo "=== MAĞAZA BAZLI HEDEF VE GERÇEKLEŞEN ANALİZİ ===\n\n";

$excelExtractor = new \App\ExcelExtractor();
$pdfExtractor = new \App\PdfExtractor();
$pdfExtractor->setUseOcr(true);

$reconciler = new \App\Reconciler($excelExtractor, $pdfExtractor);
$result = $reconciler->reconcile($excelPath, array_values($pdfPaths), '1');

$matchedSet = array_flip($result->matched);
$missingSet = array_flip($result->missingInStore);

// Excel'i detaylı oku
$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($excelPath);
$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray();

$storeStats = [];

foreach (array_slice($rows, 1) as $r) {
    $barcode = null;
    $rawStore = trim((string)($r[5] ?? ''));

    // Barkod bul
    foreach ($r as $val) {
        $digits = preg_replace('/\D/', '', trim((string)$val));
        if (is_string($digits) && strlen($digits) >= 15 && strlen($digits) <= 20) {
            $barcode = $digits;
            break;
        }
    }

    if ($barcode === null) {
        continue;
    }

    // Magaza tespiti
    $group = 'Diğer / Yüklenmeyen Mağazalar';
    if (str_contains($rawStore, 'T285')) {
        $group = 'T285 EDR SARACLAR CD (PDF Var)';
    } elseif (str_contains($rawStore, 'T3048')) {
        $group = 'T3048 EDR ERASTA (PDF Var)';
    } elseif (str_contains($rawStore, 'T373')) {
        $group = 'T373 EDR MARGI AVM (PDF Var)';
    } elseif (str_contains($rawStore, 'T649')) {
        $group = 'T649 EDR ERASTA AVM (PDF Var)';
    }

    if (!isset($storeStats[$group])) {
        $storeStats[$group] = [
            'excel_total' => 0,
            'matched' => 0,
            'missing' => 0,
            'missing_barcodes' => []
        ];
    }

    $storeStats[$group]['excel_total']++;

    if (isset($matchedSet[$barcode])) {
        $storeStats[$group]['matched']++;
    } else {
        $storeStats[$group]['missing']++;
        $storeStats[$group]['missing_barcodes'][] = [
            'barcode' => $barcode,
            'store_raw' => $rawStore,
            'irsaliye' => $r[2] ?? ''
        ];
    }
}

echo sprintf("%-35s | %-12s | %-12s | %-12s | %s\n", "Mağaza Grubu", "Excel Toplam", "Eşleşen", "Eksik Kalan", "Başarı Oranı");
echo str_repeat("-", 85) . "\n";

$total4Excel = 0;
$total4Matched = 0;
$total4Missing = 0;

foreach ($storeStats as $group => $st) {
    $pct = round(($st['matched'] / max(1, $st['excel_total'])) * 100, 1);
    echo sprintf(
        "%-35s | %-12d | %-12d | %-12d | %%%s\n",
        $group,
        $st['excel_total'],
        $st['matched'],
        $st['missing'],
        $pct
    );
    if ($group !== 'Diğer / Yüklenmeyen Mağazalar') {
        $total4Excel += $st['excel_total'];
        $total4Matched += $st['matched'];
        $total4Missing += $st['missing'];
    }
}

echo str_repeat("=", 85) . "\n";
$total4Pct = round(($total4Matched / max(1, $total4Excel)) * 100, 1);
echo sprintf(
    "%-35s | %-12d | %-12d | %-12d | %%%s\n",
    "YÜKLENEN 4 MAĞAZANIN TOPLAMI",
    $total4Excel,
    $total4Matched,
    $total4Missing,
    $total4Pct
);

echo "\n--- DİĞER / YÜKLENMEYEN MAĞAZALARIN DETAYLARI (PDF'i olmayan koliler) ---\n";
$otherStoreCounts = [];
foreach ($storeStats['Diğer / Yüklenmeyen Mağazalar']['missing_barcodes'] as $mb) {
    $s = $mb['store_raw'] !== '' ? $mb['store_raw'] : 'Boş / Bilinmeyen';
    $otherStoreCounts[$s] = ($otherStoreCounts[$s] ?? 0) + 1;
}
foreach ($otherStoreCounts as $s => $cnt) {
    echo "- {$s}: {$cnt} adet koli\n";
}
