<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Logger;

$baseDir = dirname(__DIR__);
$targetDirs = [
    $baseDir . '/var/uploads',
    $baseDir . '/var/tmp'
];

Logger::log("[Cron-Cleanup] Geçici dosya temizleme işlemi başladı.");

$deletedCount = 0;
$timeLimit = time() - (24 * 3600); // 24 saat öncesi

foreach ($targetDirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $fileInfo) {
        $filePath = $fileInfo->getRealPath();
        if ($filePath === false) {
            continue;
        }
        
        // 24 saatten eski dosyaları/klasörleri temizle
        if ($fileInfo->getMTime() < $timeLimit) {
            if ($fileInfo->isFile()) {
                if (@unlink($filePath)) {
                    $deletedCount++;
                }
            } elseif ($fileInfo->isDir()) {
                // Boş dizinleri temizle
                @rmdir($filePath);
            }
        }
    }
}

// Log dosyası temizleme (Log Rotation) işlemi
$logFile = $baseDir . '/var/logs/app.log';
if (is_file($logFile)) {
    // 10 MB limit (10 * 1024 * 1024)
    if (filesize($logFile) > 10485760) {
        $backupFile = $baseDir . '/var/logs/app.log.bak';
        @unlink($backupFile); // Varsa eski yedeği sil
        if (@rename($logFile, $backupFile)) {
            Logger::log("[Cron-Cleanup] Log dosyası 10MB boyutunu aştığı için sıfırlandı. Eski loglar app.log.bak dosyasına aktarıldı.");
        }
    }
}

Logger::log("[Cron-Cleanup] Temizleme tamamlandı. Toplam silinen geçici dosya adedi: {$deletedCount}");
echo "Temizleme tamamlandı. Toplam silinen geçici dosya adedi: {$deletedCount}\n";

