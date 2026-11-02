<?php

declare(strict_types=1);

// Load .env configuration
$envFile = dirname(__DIR__) . '/.env';
$envVars = [];
if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                $v = substr($v, 1, -1);
            }
            $envVars[$k] = $v;
        }
    }
}

$authEmail = $envVars['AUTH_EMAIL'] ?? (getenv('AUTH_EMAIL') ?: '');
$authPassword = $envVars['AUTH_PASSWORD'] ?? (getenv('AUTH_PASSWORD') ?: '');
$authSecret = $envVars['AUTH_SECRET'] ?? (getenv('AUTH_SECRET') ?: 'hbc_secret_key_2026');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['SERVER_PORT'] ?? 80) == 443);

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_name('HBC_MUTABAKAT_SESSID');
    ini_set('session.name', 'HBC_MUTABAKAT_SESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$rememberCookieName = 'hbc_remember';
$rememberDays = 30;

// Auto-login via "Beni Hatırla" cookie if session is not active
if (empty($_SESSION['hbc_auth']) && !empty($_COOKIE[$rememberCookieName]) && $authEmail !== '' && $authPassword !== '') {
    $decoded = base64_decode((string)$_COOKIE[$rememberCookieName], true);
    if (is_string($decoded)) {
        $parts = explode('|', $decoded);
        if (count($parts) === 3) {
            [$cookieEmail, $cookieExpires, $cookieHmac] = $parts;
            $expiresInt = (int)$cookieExpires;
            if ($expiresInt > time() && strtolower($cookieEmail) === strtolower($authEmail)) {
                $expectedHmac = hash_hmac('sha256', strtolower($authEmail) . '|' . $expiresInt, $authSecret . '|' . $authPassword);
                if (hash_equals($expectedHmac, $cookieHmac)) {
                    $_SESSION['hbc_auth'] = true;
                    $_SESSION['hbc_user'] = $authEmail;
                }
            }
        }
    }
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'login') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $rememberMe = !empty($_POST['remember_me']);

    if ($authEmail !== '' && $authPassword !== '' && strtolower($email) === strtolower($authEmail) && hash_equals($authPassword, $password)) {
        $_SESSION['hbc_auth'] = true;
        $_SESSION['hbc_user'] = $authEmail;
        $_SESSION['hbc_login_at'] = time();

        if ($rememberMe) {
            $expires = time() + (86400 * $rememberDays);
            $hmac = hash_hmac('sha256', strtolower($authEmail) . '|' . $expires, $authSecret . '|' . $authPassword);
            $token = base64_encode($authEmail . '|' . $expires . '|' . $hmac);
            setcookie($rememberCookieName, $token, [
                'expires' => $expires,
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie($rememberCookieName, '', [
                'expires' => time() - 42000,
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        header('Location: index.php');
        exit;
    } else {
        $loginError = 'Geçersiz e-posta adresi veya şifre girdiniz.';
    }
}

if (($_GET['action'] ?? '') === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    setcookie($rememberCookieName, '', [
        'expires' => time() - 42000,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_destroy();
    header('Location: index.php');
    exit;
}

$isAuthenticated = !empty($_SESSION['hbc_auth']);

if (!$isAuthenticated) {
    $isLoginAttempt = ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_GET['action'] ?? '') === 'login' || isset($_POST['password'])));
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

    if (!$isLoginAttempt && ($_SERVER['REQUEST_METHOD'] === 'POST' || $isAjax)) {
        header('Content-Type: application/json; charset=utf-8', true, 401);
        echo json_encode(['success' => false, 'message' => 'Oturum süresi doldu veya yetkisiz erişim. Lütfen giriş yapın.']);
        exit;
    }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Yap — HBC Nakliyat Mutabakat Portalı</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <style>
        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .login-card {
            width: 100%;
            max-width: 440px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2.5rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-logo {
            font-size: 3rem;
            margin-bottom: 0.75rem;
        }
        .login-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            margin: 0 0 0.5rem 0;
        }
        .login-header p {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin: 0;
        }
        .login-form .form-group {
            margin-bottom: 1.25rem;
        }
        .login-form label.field-label {
            display: block;
            font-size: 0.88rem;
            font-weight: 600;
            margin-bottom: 0.4rem;
            color: var(--text-main);
        }
        .login-form input[type="email"],
        .login-form input[type="password"] {
            width: 100%;
            padding: 0.8rem 1rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--bg-main);
            color: var(--text-main);
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .login-form input[type="email"]:focus,
        .login-form input[type="password"]:focus {
            border-color: var(--color-primary);
        }
        .remember-row {
            display: flex;
            align-items: center;
            margin-bottom: 1.25rem;
        }
        .remember-label {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: var(--text-main);
            cursor: pointer;
            user-select: none;
        }
        .remember-label input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--color-primary, #3182ce);
            cursor: pointer;
        }
        .login-error {
            background: rgba(229, 62, 62, 0.15);
            color: #fc8181;
            border: 1px solid rgba(229, 62, 62, 0.3);
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            margin-bottom: 1.25rem;
            text-align: center;
        }
        .login-footer {
            margin-top: 2rem;
            text-align: center;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">🚚</div>
                <h1>HBC Nakliyat</h1>
                <p>Operasyon ve Mutabakat Portalı Girişi</p>
            </div>
            
            <?php if (!empty($loginError)): ?>
                <div class="login-error"><?= htmlspecialchars($loginError) ?></div>
            <?php endif; ?>

            <form action="index.php?action=login" method="POST" class="login-form">
                <div class="form-group">
                    <label for="email" class="field-label">Yetkili E-Posta</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="ornek@hbcnakliyat.com" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password" class="field-label">Şifre</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="••••••••">
                </div>
                <div class="remember-row">
                    <label class="remember-label" for="remember_me">
                        <input type="checkbox" id="remember_me" name="remember_me" value="1" checked>
                        <span>Beni Hatırla</span>
                    </label>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.85rem; font-weight: 700; margin-top: 0.25rem;">
                    Güvenli Giriş Yap
                </button>
            </form>

            <div class="login-footer">
                &copy; <?= date('Y') ?> HBC Nakliyat Lojistik A.Ş. • Tüm hakları saklıdır.
            </div>
        </div>
    </div>
</body>
</html>
<?php
    exit;
}

// Prevent browser/server caching entirely
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\ExcelExtractor;
use App\PdfExtractor;
use App\Reconciler;

// Initialize config
$config = require dirname(__DIR__) . '/config.php';

// Ensure upload directory exists
if (!is_dir($config['upload_dir'])) {
    mkdir($config['upload_dir'], 0777, true);
}

$action = $_GET['action'] ?? '';

// Handle Reconcile Request via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reconcile') {
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    header('X-Accel-Buffering: no'); // Nginx'in veriyi buffer'lamasını engeller, anında gönderir
    @set_time_limit(600); // 10 dakika limit
    
    // PHP'nin çıktı önbelleğini kapatıp veriyi anlık göndermesini sağlıyoruz
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);
    
    $startTime = microtime(true);
    \App\Logger::log("----------------------------------------------------------------");
    \App\Logger::log("MUTABAKAT BAŞLADI - Plaka/Mağaza: " . ($_POST['store_name'] ?? 'Belirtilmemiş'));
    
    try {
        if (!isset($_FILES['excel_file']) || !isset($_FILES['pdf_file'])) {
            echo json_encode(['success' => false, 'message' => 'Lütfen hem Excel/CSV hem de PDF dosyasını yükleyin.']);
            exit;
        }

        $excelFile = $_FILES['excel_file'];
        $pdfFile = $_FILES['pdf_file'];

        if ($excelFile['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Excel/CSV dosyası yüklenirken hata oluştu.']);
            exit;
        }

        $pdfPaths = [];
        $pdfOriginalFilenames = [];
        if (is_array($pdfFile['error'])) {
            foreach ($pdfFile['error'] as $key => $error) {
                if ($error !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'message' => 'PDF dosyalarından biri yüklenirken hata oluştu.']);
                    exit;
                }
                $origName = $pdfFile['name'][$key];
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if (str_starts_with($origName, 'desktop') || $ext !== 'pdf') {
                    \App\Logger::log("[Upload] PDF olmayan dosya es geçildi: " . $origName);
                    continue;
                }
                $pdfPaths[] = $pdfFile['tmp_name'][$key];
                $pdfOriginalFilenames[$pdfFile['tmp_name'][$key]] = $origName;
            }
        } else {
            if ($pdfFile['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'PDF dosyası yüklenirken hata oluştu.']);
                exit;
            }
            $origName = $pdfFile['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (str_starts_with($origName, 'desktop') || $ext !== 'pdf') {
                \App\Logger::log("[Upload] PDF olmayan dosya es geçildi: " . $origName);
            } else {
                $pdfPaths[] = $pdfFile['tmp_name'];
                $pdfOriginalFilenames[$pdfFile['tmp_name']] = $origName;
            }
        }

        if (empty($pdfPaths)) {
            echo json_encode(['success' => false, 'message' => 'Lütfen geçerli en az bir PDF dosyası yükleyin.']);
            exit;
        }

        $excelPath = $excelFile['tmp_name'];

        $excelExtractor = new ExcelExtractor();
        $pdfExtractor = new PdfExtractor();
        
        // Görsel OCR Modu varsayılan olarak her zaman aktiftir
        $pdfExtractor->setUseOcr(true);

        $reconciler = new Reconciler($excelExtractor, $pdfExtractor);

        $storeName = trim((string)($_POST['store_name'] ?? ''));
        if ($storeName === '') {
            $storeName = 'Eşleşme Sonucu';
        }

        $companyId = trim((string)($_POST['company_id'] ?? '1'));

        $result = $reconciler->reconcile($excelPath, $pdfPaths, $companyId);

        // Generate barcode to store mapping
        $excelMap = $excelExtractor->extractMap($excelPath);
        $barcodeStores = $excelMap;
        foreach ($pdfPaths as $path) {
            $pdfMap = $pdfExtractor->extractBarcodeStoreMap($path, $pdfOriginalFilenames[$path] ?? null);
            foreach ($pdfMap as $barcode => $pdfStoreName) {
                if (!isset($barcodeStores[$barcode])) {
                    $barcodeStores[$barcode] = $pdfStoreName;
                }
            }
        }

        $elapsed = round(microtime(true) - $startTime, 4);
        \App\Logger::log("MUTABAKAT BAŞARIYLA TAMAMLANDI - Toplam Süre: {$elapsed} saniye");
        \App\Logger::log("Excel Barkod: " . count($result->terminalBarcodes) . " | PDF Barkod: " . count($result->storeBarcodes));
        \App\Logger::log("Eşleşen: " . count($result->matched) . " | Eksik: " . count($result->missingInStore) . " | Fazla: " . count($result->extraInStore) . " | Şüpheli: " . count($result->suspectedMatches));

        echo json_encode([
            'success' => true,
            'store_name' => $storeName,
            'saved_id' => null,
            'result' => $result->toArray(),
            'barcode_stores' => $barcodeStores,
            'pdf_original_words' => $pdfExtractor->getBarcodeToOriginalMap(),
            'pdf_mismatches' => $pdfExtractor->getMismatches(),
            'elapsed_time' => $elapsed,
        ]);
        exit;
    } catch (\Throwable $e) {
        \App\Logger::log("HATA OLUŞTU: [" . get_class($e) . "] " . $e->getMessage() . " | Yer: " . $e->getFile() . ":" . $e->getLine());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Handle Export Excel for dynamic data
if ($action === 'export-excel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonData = $_POST['data'] ?? '';
    $storeName = $_POST['store_name'] ?? 'Mutabakat Raporu';
    $data = json_decode($jsonData, true);
    
    if (is_array($data)) {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $sheet->setCellValue('A1', 'El Terminali (Excel) Barkodu');
        $sheet->setCellValue('B1', 'PDF Barkodu');
        $sheet->setCellValue('C1', 'Mağaza Adı');
        $sheet->setCellValue('D1', 'Durum');
        $sheet->setCellValue('E1', 'Açıklama');
        
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        
        $rowIdx = 2;
        foreach ($data as $row) {
            $sheet->setCellValueExplicit('A' . $rowIdx, $row['terminal_barcode'] ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B' . $rowIdx, $row['pdf_barcode'] ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('C' . $rowIdx, $row['store_name'] ?? '');
            $sheet->setCellValue('D' . $rowIdx, $row['status'] ?? '');
            $sheet->setCellValue('E' . $rowIdx, $row['description'] ?? '');
            $rowIdx++;
        }
        
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="mutabakat_' . preg_replace('/[^a-zA-Z0-9]/', '_', $storeName) . '.xlsx"');
        header('Cache-Control: max-age=0');
        
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HBC Mutabakat - Hızlı & Deterministik Karşılaştırma Modülü</title>
    <meta name="description" content="El terminali verileri ile mağaza PDF çıktılarını saniyeler içinde karşılaştıran deterministik veri mutabakat modülü.">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Custom Style CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
</head>
<body>
    <div class="glow-bg"></div>
    
    <div class="container">
        <!-- Header -->
        <header class="app-header animate-fade-in">
            <div class="logo">
                <span class="logo-icon">⚡</span>
                <div class="logo-text">
                    <h1>HBC Mutabakat</h1>
                    <p>Deterministik Veri Eşleştirme Motoru</p>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <div class="system-status">
                    <span class="status-badge status-active">
                        <span class="pulse-dot"></span> Sistem Aktif
                    </span>
                </div>
                <div class="user-auth-bar" style="display: flex; align-items: center; gap: 10px;">
                    <span class="status-badge" style="background: rgba(49, 130, 206, 0.15); color: #3182ce; border: 1px solid rgba(49, 130, 206, 0.3);">
                        👤 <?= htmlspecialchars((string)($_SESSION['hbc_user'] ?? '')) ?>
                    </span>
                    <a href="index.php?action=logout" class="btn btn-sm" style="background: #e53e3e; color: #fff; padding: 6px 14px; border-radius: 6px; text-decoration: none; font-size: 0.85rem; font-weight: 600;">Çıkış Yap</a>
                </div>
            </div>
        </header>

        <!-- Main Panel Grid -->
        <main class="main-content">
            <!-- Upload Panel -->
            <section class="card upload-card animate-slide-up">
                <div class="card-header">
                    <h2>Mutabakat Başlat</h2>
                    <p>Excel/CSV dosyasını ve Mağaza PDF çıktısını yükleyerek eşleştirmeyi saniyeler içinde tamamlayın.</p>
                </div>
                
                <form id="reconcileForm" class="reconcile-form">
                    <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1.5rem;">
                        <div class="form-group" style="flex: 1; margin-bottom: 0;">
                            <label for="company_id">Firma</label>
                            <select id="company_id" name="company_id" required style="width: 100%; padding: 0.75rem; border-radius: 8px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); font-family: inherit; font-size: 0.95rem; outline: none; cursor: pointer; transition: border-color 0.2s;">
                                <option value="1" selected>LCW</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1; margin-bottom: 0;">
                            <label for="store_name">Araç Plakası <span class="optional-text">(İsteğe Bağlı)</span></label>
                            <input type="text" id="store_name" name="store_name" placeholder="Örn: 34ABC123" style="width: 100%;">
                        </div>
                    </div>

                    <div class="dropzones-container">
                        <!-- Left Dropzone: Excel/CSV -->
                        <div class="dropzone" id="excelDropzone">
                            <input type="file" id="excel_file" name="excel_file" accept=".xlsx,.xls,.csv" required hidden>
                            <div class="dropzone-content">
                                <div class="dropzone-icon">📊</div>
                                <h3>El Terminali Dosyası</h3>
                                <p>Sürükle-bırak veya tıklayarak seç</p>
                                <span class="file-spec">.xlsx, .xls, .csv formatları</span>
                            </div>
                            <div class="selected-file-info" style="display:none;">
                                <span class="file-name"></span>
                                <button type="button" class="remove-file-btn">&times;</button>
                            </div>
                        </div>

                        <!-- Right Dropzone: PDF -->
                        <div class="dropzone" id="pdfDropzone">
                            <input type="file" id="pdf_file" name="pdf_file[]" accept=".pdf" required hidden multiple>
                            <div class="dropzone-content">
                                <div class="dropzone-icon">📕</div>
                                <h3>Mağaza PDF Çıktıları</h3>
                                <p>Sürükle-bırak veya tıklayarak seç</p>
                                <span class="file-spec">Birden çok .pdf seçilebilir</span>
                            </div>
                            <div class="selected-file-info" style="display:none;">
                                <span class="file-name"></span>
                                <button type="button" class="remove-file-btn">&times;</button>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" id="submitBtn" style="position: relative; display: inline-flex; align-items: center; justify-content: center; gap: 0.75rem;">
                        <span class="btn-text">Karşılaştırmayı Çalıştır</span>
                        <span id="liveTimer" style="display: none; font-weight: 600; background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 4px; font-size: 0.85rem;">0.0 sn</span>
                        <div class="loader" style="display:none; margin: 0;"></div>
                    </button>
                </form>
            </section>

            <!-- Results Section (Initially Hidden) -->
            <section class="card results-card" id="resultsSection" style="display:none;">
                <div class="results-header">
                    <div>
                        <h2 id="resultStoreName">Mutabakat Sonuçları</h2>
                        <p class="timestamp" id="reconciliationTime">İşlem anlık olarak tamamlandı.</p>
                    </div>
                    <div class="action-buttons" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button type="button" class="btn btn-secondary" id="showRawDataBtn" style="display: none;">Ham Barkodları Göster</button>
                        <button type="button" class="btn btn-secondary" id="downloadExcelBtn">Excel İndir</button>
                    </div>
                </div>

                <!-- PDF Line Mismatches Warning (Initially Hidden) -->
                <div id="mismatchContainer" style="display:none; margin-bottom: 1.5rem;"></div>

                <!-- Stats Grid -->
                <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
                    <div class="stat-box stat-matched">
                        <div class="stat-value" id="statMatchedVal">0</div>
                        <div class="stat-label">Tam Eşleşen Koliler</div>
                        <div class="stat-desc" id="statMatchedDesc">Her iki listede de olanlar</div>
                    </div>
                    <div class="stat-box stat-missing">
                        <div class="stat-value" id="statMissingVal">0</div>
                        <div class="stat-label">Eksik Koliler</div>
                        <div class="stat-desc">Terminalde var, PDF'te yok</div>
                    </div>
                    <div class="stat-box stat-extra">
                        <div class="stat-value" id="statExtraVal">0</div>
                        <div class="stat-label">Fazla Koliler</div>
                        <div class="stat-desc">PDF'te var, Terminalde yok</div>
                    </div>
                    <div class="stat-box stat-suspected">
                        <div class="stat-value" id="statSuspectedVal">0</div>
                        <div class="stat-label">Şüpheli Eşleşmeler</div>
                        <div class="stat-desc">Yakın benzerlik, manuel onay bekliyor</div>
                    </div>
                    <div class="stat-box stat-invalid">
                        <div class="stat-value" id="statInvalidVal">0</div>
                        <div class="stat-label">Hatalı Barkodlar</div>
                        <div class="stat-desc">Uzunluk kuralına uymayanlar</div>
                    </div>
                </div>

                <!-- Filter & Search Bar -->
                <div class="filter-search-bar">
                    <div class="filter-tabs">
                        <button class="tab-btn active" data-filter="all">Tümü (<span id="countAll">0</span>)</button>
                        <button class="tab-btn tab-missing" data-filter="missing">Eksikler (<span id="countMissing">0</span>)</button>
                        <button class="tab-btn tab-extra" data-filter="extra">Fazlalar (<span id="countExtra">0</span>)</button>
                        <button class="tab-btn tab-suspected" data-filter="suspected">Şüpheli (<span id="countSuspected">0</span>)</button>
                        <button class="tab-btn tab-invalid" data-filter="invalid">Hatalı (<span id="countInvalid">0</span>)</button>
                        <button class="tab-btn tab-matched" data-filter="matched">Eşleşenler (<span id="countMatched">0</span>)</button>
                    </div>
                    <div class="search-box">
                        <input type="text" id="tableSearch" placeholder="Barkod Ara...">
                    </div>
                </div>

                <!-- Table Wrapper -->
                <div class="table-wrapper">
                    <table class="report-table" id="reportTable">
                        <thead>
                            <tr>
                                <th>El Terminali (Excel) Barkodu</th>
                                <th>PDF Barkodu</th>
                                <th>Mağaza Adı</th>
                                <th>Durum</th>
                                <th>Açıklama</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <!-- JS will inject rows here -->
                        </tbody>
                    </table>
                    <div id="noDataMsg" class="no-data-msg" style="display:none;">Eşleşen kayıt bulunamadı.</div>
                </div>
            </section>
        </main>

        <!-- Footer -->
        <footer class="app-footer" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <p>&copy; <?= date('Y') ?> HBC Mutabakat.</p>
            <p style="font-size: 0.8rem; color: var(--text-muted);">Aktif Sunucu IP: <strong><?= $_SERVER['SERVER_ADDR'] ?? 'Bilinmiyor' ?></strong></p>
        </footer>
    </div>

    <!-- Application Script -->
    <script src="assets/js/app.js?v=<?= time() ?>"></script>
</body>
</html>
