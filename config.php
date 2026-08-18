<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// Load .env variables
\App\Env::load(__DIR__);

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
        'enabled' => false,
    ],
    'google_vision_api_key' => \App\Env::get('GOOGLE_VISION_API_KEY', ''),
    'upload_dir' => __DIR__ . '/var/uploads',
    'reports_dir' => __DIR__ . '/var/reports',
    'ocr_workers' => $dynamicOcrWorkers,
    'ocr_engine' => 'google', // 'google' (Google Cloud Vision), 'paddle' veya 'tesseract'
    'lock_file' => __DIR__ . '/var/hbc_reconcile_ocr.lock',
];
