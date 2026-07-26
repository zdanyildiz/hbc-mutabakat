<?php

declare(strict_types=1);

// GitHub Webhook Deploy Script
// Bu dosya GitHub'a her push yapıldığında sunucuda otomatik 'git pull' çalıştırır.

$config = require dirname(__DIR__) . '/config.php';
$secret = getenv('DEPLOY_SECRET') ?: ($config['deploy_secret'] ?? '');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? '';

if (empty($signature)) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Erişim reddedildi: Webhook imzası (HTTP_X_HUB_SIGNATURE) bulunamadı.';
    exit;
}

$postData = file_get_contents('php://input');
if ($postData === false || $postData === '') {
    header('HTTP/1.1 400 Bad Request');
    echo 'Boş istek gövdesi';
    exit;
}

$parts = explode('=', $signature, 2);
if (count($parts) === 2) {
    list($algo, $hash) = $parts;
    $payloadHash = hash_hmac($algo, $postData, $secret);
    if (!hash_equals($hash, $payloadHash)) {
        header('HTTP/1.1 403 Forbidden');
        echo 'İmza doğrulanamadı (Invalid signature)';
        exit;
    }
} else {
    header('HTTP/1.1 400 Bad Request');
    echo 'Geçersiz imza formatı';
    exit;
}

// Git pull komutunu çalıştır
$output = [];
$returnVar = 0;
// git config safe.directory ayarı root dışında www-data için de gerekebilir
exec('git config --global --add safe.directory /var/www/mutabakat 2>&1');
exec('git pull 2>&1', $output, $returnVar);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => $returnVar === 0,
    'output' => $output
], JSON_UNESCAPED_UNICODE);
