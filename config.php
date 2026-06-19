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
];
