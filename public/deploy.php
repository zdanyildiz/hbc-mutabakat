<?php

declare(strict_types=1);

// GitHub Webhook Deploy Script
// Bu dosya GitHub'a her push yapıldığında sunucuda otomatik 'git pull' çalıştırır.

$secret = 'hbcnakliyat_secret_token_123'; // Güvenlik tokenı
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? '';

if ($signature !== '') {
    $postData = file_get_contents('php://input');
    if ($postData !== false) {
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
    }
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
