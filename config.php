<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// Load .env variables
\App\Env::load(__DIR__);

return [
    'db' => [
        'host' => 'localhost',
        'dbname' => 'hbc_mutabakat',
        'username' => 'root',
        'password' => '',
        'enabled' => false,
    ],
    'google_docai_project_id' => \App\Env::get('GOOGLE_DOCAI_PROJECT_ID', ''),
    'google_docai_location' => \App\Env::get('GOOGLE_DOCAI_LOCATION', 'eu'),
    'google_docai_processor_id' => \App\Env::get('GOOGLE_DOCAI_PROCESSOR_ID', ''),
    'google_docai_api_key' => \App\Env::get('GOOGLE_DOCAI_API_KEY', ''),
    'google_application_credentials' => \App\Env::get('GOOGLE_APPLICATION_CREDENTIALS', ''),
    'google_vision_api_key' => \App\Env::get('GOOGLE_VISION_API_KEY', ''),
    'upload_dir' => __DIR__ . '/var/uploads',
    'reports_dir' => __DIR__ . '/var/reports',
];
