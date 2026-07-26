<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => 'localhost',
        'dbname' => 'hbc_mutabakat',
        'username' => 'root',
        'password' => '',
        'enabled' => false, // Linux sunucuda veya yerelde MySQL kullanılacaksa true yapılıp bilgiler girilebilir.
    ],
    'upload_dir' => dirname(__DIR__) . '/var/uploads',
    'reports_dir' => dirname(__DIR__) . '/var/reports',
    'ocr_workers' => 3,
    'lock_file' => dirname(__DIR__) . '/var/hbc_reconcile_ocr.lock',
    'enable_debug' => false,
    'debug_key' => 'hbcnakliyat_debug_key_2026',
    'deploy_secret' => 'hbcnakliyat_secret_token_123',
];

