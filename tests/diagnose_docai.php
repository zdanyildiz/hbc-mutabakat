<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
\App\Env::load(dirname(__DIR__));

$credsPath = \App\Env::get('GOOGLE_APPLICATION_CREDENTIALS');
echo 'Creds path: ' . $credsPath . ' (exists: ' . (file_exists((string)$credsPath) ? 'YES' : 'NO') . ')' . PHP_EOL;

$content = file_get_contents((string)$credsPath);
$json = json_decode((string)$content, true);
echo 'Creds type: ' . ($json['type'] ?? 'NONE') . PHP_EOL;

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'client_id' => $json['client_id'] ?? '',
    'client_secret' => $json['client_secret'] ?? '',
    'refresh_token' => $json['refresh_token'] ?? '',
    'grant_type' => 'refresh_token',
]));
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo 'Token HTTP Code: ' . $code . PHP_EOL;
echo 'Token response snippet: ' . substr((string)$res, 0, 100) . PHP_EOL;

$tokenData = json_decode((string)$res, true);
$token = $tokenData['access_token'] ?? '';

// Sayfa oluşturma testi
$tempDir = dirname(__DIR__) . '/var/tmp';
@mkdir($tempDir, 0777, true);
$tempPrefix = $tempDir . '/testpage_' . uniqid();

$pdfPath = file_exists(dirname(__DIR__) . '/T285.pdf') ? dirname(__DIR__) . '/T285.pdf' : 'C:\\Users\\zdany\\Downloads\\wetransfer_yeni-klasor-3_2026-08-18_1418\\Yeni klasör (3)\\T285.pdf';
$cmd = 'pdftoppm -png -r 200 ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tempPrefix);
$out = shell_exec($cmd);
$files = glob($tempPrefix . '-*.png');
echo 'pdftoppm generated files count: ' . count($files ?: []) . PHP_EOL;

if (!empty($files)) {
    $firstImg = $files[0];
    echo 'First image: ' . $firstImg . ' size: ' . filesize($firstImg) . PHP_EOL;
    
    // Tek sayfa Document AI isteği
    $url = 'https://eu-documentai.googleapis.com/v1/projects/hbc-mutabakat/locations/eu/processors/78b09964ff7804f4:process';
    $payload = json_encode([
        'rawDocument' => [
            'content' => base64_encode((string)file_get_contents($firstImg)),
            'mimeType' => 'image/png'
        ],
        'skipHumanReview' => true
    ]);
    
    $ch2 = curl_init($url);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_POST, true);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, (string)$payload);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Bearer ' . $token
    ]);
    $res2 = curl_exec($ch2);
    $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $err2 = curl_error($ch2);
    echo 'DocAI Call HTTP Code: ' . $code2 . PHP_EOL;
    if ($err2) echo 'cURL Error: ' . $err2 . PHP_EOL;
    echo 'DocAI Response snippet: ' . substr((string)$res2, 0, 350) . PHP_EOL;
}
