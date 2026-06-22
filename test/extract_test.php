<?php

declare(strict_types=1);

$inputFile = __DIR__ . '/t325-metin.txt';
$outputFile = __DIR__ . '/t325_cikti.txt';

if (!file_exists($inputFile)) {
    echo "Hata: {$inputFile} dosyası bulunamadı!\n";
    exit(1);
}

// PHP character replace map (Reconciler.php & reconcile.py combination)
$charReplaceMap = [
    'l' => '1', 'ı' => '1', 'I' => '1', 'i' => '1', '!' => '1', '|' => '1', 't' => '1',
    '[' => '1', ']' => '3', 'j' => '3', 'J' => '3', '{' => '8', '}' => '1',
    'B' => '8', 'E' => '8',
    'M' => '0', 'O' => '0', 'o' => '0',
    'S' => '5', 's' => '5',
    'ü' => '4'
];

$content = file_get_contents($inputFile);
$lines = explode("\n", $content);

$output = "";
$output .= sprintf("%-5s | %-40s | %-30s | %-20s\n", "Satır", "Orijinal OCR Satırı", "Düzeltilmiş ve Arındırılmış", "Ayıklanan Barkod");
$output .= str_repeat("-", 105) . "\n";

$barcodeCount = 0;

foreach ($lines as $lineIdx => $line) {
    $line = trim($line);
    
    // Sayfa başlıklarını ve boşlukları atla
    if ($line === '' || str_contains($line, 'SAYFA') || str_contains($line, '===')) {
        continue;
    }

    // Satır numarası ayıklama (Örn: " 15 | satır içeriği")
    if (preg_match('/^\s*\d+\s*\|\s*(.*)$/u', $line, $matches)) {
        $rawText = trim($matches[1]);
    } else {
        $rawText = $line;
    }

    if ($rawText === '') {
        continue;
    }

    // 1. Boşlukları temizle
    $cleanLine = (string)preg_replace('/\s+/u', '', $rawText);
    
    // 2. Karakter değişimi
    $replacedLine = strtr($cleanLine, $charReplaceMap);
    
    // 3. Sayı dışındakileri temizle
    $digitsOnly = (string)preg_replace('/\D/u', '', $replacedLine);

    // 4. 14-22 hane arası barkod ayıkla (18 hane standart barkodlar için)
    $extracted = '-';
    if (preg_match('/(\d{14,22})/', $digitsOnly, $barcodeMatches)) {
        $extracted = $barcodeMatches[1];
        $barcodeCount++;
    }

    // Çıktıyı zenginleştir
    $output .= sprintf(
        "%-5d | %-40s | %-30s | %-20s\n",
        $lineIdx + 1,
        mb_substr($rawText, 0, 40),
        mb_substr($digitsOnly, 0, 30),
        $extracted
    );
}

$output .= str_repeat("-", 105) . "\n";
$output .= "Toplam Okunan Satır: " . count($lines) . "\n";
$output .= "Toplam Ayıklanan Barkod (14-22 hane): " . $barcodeCount . "\n";

// Çıktıyı dosyaya yaz
file_put_contents($outputFile, $output);

// Ekrana da yaz
echo $output;
echo "\nÇıktı başarıyla '{$outputFile}' dosyasına kaydedildi.\n";
