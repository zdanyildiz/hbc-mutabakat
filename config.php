<?php

declare(strict_types=1);

// Çekirdek sayısının 2/3'ü oranında dinamik OCR worker hesabı (12 vCPU sunucuda 8 worker)
$detectedCores = PHP_OS_FAMILY === 'Windows'
    ? (int)(getenv('NUMBER_OF_PROCESSORS') ?: 4)
    : (int)(@shell_exec('nproc 2>/dev/null') ?: 4);
$dynamicOcrWorkers = max(2, (int)floor($detectedCores * (2 / 3)));

return [
    'db' => [
        'host' => 'localhost',
        'dbname' => 'hbc_mutabakat',
        'username' => 'root',
        'password' => '',
        'enabled' => false, // Linux sunucuda veya yerelde MySQL kullanılacaksa true yapılıp bilgiler girilebilir.
    ],
    'upload_dir' => __DIR__ . '/var/uploads',
    'reports_dir' => __DIR__ . '/var/reports',
    'ocr_workers' => $dynamicOcrWorkers,
    'lock_file' => __DIR__ . '/var/hbc_reconcile_ocr.lock',
    'enable_debug' => false,
    'debug_key' => 'hbcnakliyat_debug_key_2026',
    'deploy_secret' => 'hbcnakliyat_secret_token_123',
];

