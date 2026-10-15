<?php

declare(strict_types=1);

// Prevent browser/server caching entirely
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Database;
use App\ExcelExtractor;
use App\PdfExtractor;
use App\Reconciler;
use App\SaasPlanCatalog;
use App\SaasQuotaException;
use App\SubscriptionService;
use App\TenantContext;
use App\TenantMiddleware;

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

// Initialize config
$config = require dirname(__DIR__) . '/config.php';

// Ensure upload directory exists
if (!is_dir($config['upload_dir'])) {
    mkdir($config['upload_dir'], 0777, true);
}

$db = new Database($config['db']);
$dbEnabled = $db->isEnabled();
if ($dbEnabled) {
    $db->initSchema();
}

$approvalSecret = isset($config['deploy_secret']) && is_string($config['deploy_secret'])
    ? $config['deploy_secret']
    : 'hbcnakliyat_secret_token_123';
$subscriptionService = new SubscriptionService($db, $approvalSecret);
$tenantMiddleware = new TenantMiddleware($subscriptionService);

/** @var array<string, mixed> $sessionData */
$sessionData = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : [];
$activeTenant = $tenantMiddleware->resolve($_REQUEST, $_SERVER, $sessionData);

$action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';

// SaaS API: Get 2026 Plan Catalog
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'saas-plans') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'plans' => SaasPlanCatalog::getAllPlans(),
        'paid_plans' => SaasPlanCatalog::getPaidPlans(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// SaaS API: Get Active Tenant & Subscription Status
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'saas-status') {
    header('Content-Type: application/json; charset=utf-8');
    $currentSub = $activeTenant !== null ? $subscriptionService->getCurrentSubscription($activeTenant->id) : null;
    echo json_encode([
        'success' => true,
        'tenant' => $activeTenant !== null ? $activeTenant->toArray() : null,
        'subscription' => $currentSub !== null ? $currentSub->toArray() : null,
        'plans' => SaasPlanCatalog::getAllPlans(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// SaaS API: Self-Service Tenant Registration (14-Day / 50 OCR Trial)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saas-register') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $registeredTenant = $subscriptionService->registerTenantWithTrial($_POST);
        if (isset($_SESSION) && is_array($_SESSION)) {
            $_SESSION['tenant_id'] = $registeredTenant->id;
        }
        $sub = $registeredTenant->subscription;
        echo json_encode([
            'success' => true,
            'message' => '14 Gün / 50 Adet Koli & İrsaliye OCR Ücretsiz Deneme aboneliğiniz başarıyla başlatıldı.',
            'tenant' => $registeredTenant->toArray(),
            'subscription' => $sub !== null ? $sub->toArray() : null,
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// SaaS API: Request Plan Upgrade (pending_payment)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saas-upgrade') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $targetTenantId = isset($_POST['tenant_id']) && is_numeric($_POST['tenant_id'])
            ? (int)$_POST['tenant_id']
            : ($activeTenant !== null ? $activeTenant->id : 0);
        if ($targetTenantId <= 0) {
            throw new \InvalidArgumentException('Paket yükseltme için önce firma kaydı (tenant_id) gereklidir.');
        }
        $planCode = isset($_POST['plan_code']) && is_string($_POST['plan_code'])
            ? trim($_POST['plan_code'])
            : SaasPlanCatalog::PLAN_LOGISTICS_PRO;
        $billingCycle = isset($_POST['billing_cycle']) && is_string($_POST['billing_cycle'])
            ? trim($_POST['billing_cycle'])
            : SaasPlanCatalog::CYCLE_YEARLY;
        $paymentRef = isset($_POST['payment_reference']) && is_string($_POST['payment_reference'])
            ? trim($_POST['payment_reference'])
            : null;

        $pendingSub = $subscriptionService->requestPlanUpgrade(
            $targetTenantId,
            $planCode,
            $billingCycle,
            $paymentRef
        );

        echo json_encode([
            'success' => true,
            'message' => 'Paket yükseltme talebiniz oluşturuldu (pending_payment). Ödeme/havale onaylandıktan sonra paketiniz aktifleşecektir.',
            'subscription' => $pendingSub->toArray(),
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// SaaS API: Approve Plan Upgrade (Admin / HMAC Approval -> active)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saas-approve-upgrade') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $subOrTenantId = isset($_POST['subscription_id']) && is_numeric($_POST['subscription_id'])
            ? (int)$_POST['subscription_id']
            : (isset($_POST['tenant_id']) && is_numeric($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : 0);
        $paymentRef = isset($_POST['payment_reference']) && is_string($_POST['payment_reference'])
            ? trim($_POST['payment_reference'])
            : null;
        $hmacSig = isset($_SERVER['HTTP_X_APPROVE_SIGNATURE']) && is_string($_SERVER['HTTP_X_APPROVE_SIGNATURE'])
            ? trim($_SERVER['HTTP_X_APPROVE_SIGNATURE'])
            : (isset($_POST['hmac_signature']) && is_string($_POST['hmac_signature']) ? trim($_POST['hmac_signature']) : null);
        $adminKey = isset($_POST['admin_key']) && is_string($_POST['admin_key']) ? trim($_POST['admin_key']) : '';

        if ($hmacSig === null && $adminKey !== $approvalSecret) {
            throw new \RuntimeException('Ödeme/paket aktivasyonu için geçerli HMAC imzası veya yönetici anahtarı gereklidir.');
        }

        $approvedSub = $subscriptionService->approvePlanUpgrade($subOrTenantId, $paymentRef, $hmacSig);
        echo json_encode([
            'success' => true,
            'message' => 'Paket yükseltme onaylandı, abonelik aktif edildi ve OCR kotası güncellendi.',
            'subscription' => $approvedSub->toArray(),
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Handle Reconcile Request via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reconcile') {
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

        $requestedOcrUnits = count($pdfPaths);
        $currentTenantId = TenantContext::getTenantId();
        if ($currentTenantId !== null) {
            $subscriptionService->assertCanProcessOcr($currentTenantId, $requestedOcrUnits);
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

        $result = $reconciler->reconcile($excelPath, $pdfPaths, $companyId, $currentTenantId);

        // Generate barcode to store mapping
        $excelMap = $excelExtractor->extractMap($excelPath);
        $barcodeStores = $excelMap;
        foreach ($pdfPaths as $path) {
            // Her PDF barkodunu (sadece rakam) kendi mağaza adına eşle. Böylece "Fazla Koliler"
            // satırları (yalnızca PDF'te bulunan barkodlar) doğru mağaza adını gösterir.
            // Orijinal dosya adını (T410.pdf) geçiyoruz; aksi halde "phpXXXX" geçici adı sızar.
            $pdfMap = $pdfExtractor->extractBarcodeStoreMap($path, $pdfOriginalFilenames[$path] ?? null);
            foreach ($pdfMap as $barcode => $pdfStoreName) {
                if (!isset($barcodeStores[$barcode])) {
                    $barcodeStores[$barcode] = $pdfStoreName;
                }
            }
        }

        $savedId = null;
        if ($dbEnabled) {
            $dbStart = microtime(true);
            $savedId = $db->save($storeName, $result, $companyId, $currentTenantId);
            $dbElapsed = round(microtime(true) - $dbStart, 4);
            \App\Logger::log("Veritabanı kayıt işlemi tamamlandı - Süre: {$dbElapsed} saniye (ID: {$savedId})");
        }

        $updatedSubscription = null;
        if ($currentTenantId !== null) {
            $updatedSubscription = $subscriptionService->consumeOcrQuota(
                $currentTenantId,
                $requestedOcrUnits,
                $storeName,
                $savedId
            );
        }

        $elapsed = round(microtime(true) - $startTime, 4);
        \App\Logger::log("MUTABAKAT BAŞARIYLA TAMAMLANDI - Toplam Süre: {$elapsed} saniye");
        \App\Logger::log("Excel Barkod: " . count($result->terminalBarcodes) . " | PDF Barkod: " . count($result->storeBarcodes));
        \App\Logger::log("Eşleşen: " . count($result->matched) . " | Eksik: " . count($result->missingInStore) . " | Fazla: " . count($result->extraInStore) . " | Şüpheli: " . count($result->suspectedMatches));

        echo json_encode([
            'success' => true,
            'store_name' => $storeName,
            'saved_id' => $savedId,
            'result' => $result->toArray(),
            'barcode_stores' => $barcodeStores,
            'pdf_original_words' => $pdfExtractor->getBarcodeToOriginalMap(),
            'pdf_mismatches' => $pdfExtractor->getMismatches(),
            'elapsed_time' => $elapsed,
            'saas_subscription' => $updatedSubscription !== null ? $updatedSubscription->toArray() : null,
        ]);
        exit;
    } catch (SaasQuotaException $qe) {
        \App\Logger::log("KOTA / ABONELİK ENGELİ: [" . $qe->getErrorCode() . "] " . $qe->getMessage());
        echo json_encode(array_merge($qe->toPayload(), [
            'plans' => SaasPlanCatalog::getPaidPlans(),
        ]), JSON_UNESCAPED_UNICODE);
        exit;
    } catch (\Throwable $e) {
        \App\Logger::log("HATA OLUŞTU: [" . get_class($e) . "] " . $e->getMessage() . " | Yer: " . $e->getFile() . ":" . $e->getLine());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Handle Get Report Request via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get-report') {
    header('Content-Type: application/json; charset=utf-8');
    
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id > 0 && $dbEnabled) {
        $report = $db->getById($id, TenantContext::getTenantId());
        if ($report !== null) {
            /** @var array{
             *     matched: array<string>,
             *     missingInStore: array<string>,
             *     extraInStore: array<string>,
             *     suspectedMatches?: array<array{terminal_barcode: string, store_barcode: string, distance: int}>
             * } $resultArray */
            $resultArray = json_decode($report['results_json'], true);
            echo json_encode([
                'success' => true,
                'store_name' => $report['store_name'],
                'result' => $resultArray,
            ]);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'Rapor bulunamadı veya veritabanı aktif değil.']);
    exit;
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

// Handle Export Excel for saved reports
if ($action === 'export-excel-saved') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id > 0 && $dbEnabled) {
        $report = $db->getById($id, TenantContext::getTenantId());
        if ($report !== null) {
            /** @var array{
             *     matched: array<string>,
             *     missingInStore: array<string>,
             *     extraInStore: array<string>,
             *     suspectedMatches?: array<array{terminal_barcode: string, store_barcode: string, distance: int}>
             * } $data */
            $data = json_decode($report['results_json'], true);
            
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            $sheet->setCellValue('A1', 'El Terminali (Excel) Barkodu');
            $sheet->setCellValue('B1', 'PDF Barkodu');
            $sheet->setCellValue('C1', 'Durum');
            $sheet->setCellValue('D1', 'Açıklama');
            
            $sheet->getStyle('A1:D1')->getFont()->setBold(true);
            
            $rowIdx = 2;
            foreach ($data['missingInStore'] as $barcode) {
                $sheet->setCellValueExplicit('A' . $rowIdx, $barcode, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue('B' . $rowIdx, '-');
                $sheet->setCellValue('C' . $rowIdx, 'Eksik');
                $sheet->setCellValue('D' . $rowIdx, 'Terminalde okutulmuş ancak Mağaza PDF\'inde bulunamadı.');
                $rowIdx++;
            }
            foreach ($data['extraInStore'] as $barcode) {
                $sheet->setCellValue('A' . $rowIdx, '-');
                $sheet->setCellValueExplicit('B' . $rowIdx, $barcode, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue('C' . $rowIdx, 'Fazla');
                $sheet->setCellValue('D' . $rowIdx, 'Mağaza PDF\'inde mevcut ancak Terminalde okutulmamış.');
                $rowIdx++;
            }
            foreach ($data['matched'] as $barcode) {
                $sheet->setCellValueExplicit('A' . $rowIdx, $barcode, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B' . $rowIdx, $barcode, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue('C' . $rowIdx, 'Eşleşti');
                $sheet->setCellValue('D' . $rowIdx, 'Her iki listede de başarıyla eşleşti.');
                $rowIdx++;
            }
            if (isset($data['suspectedMatches'])) {
                foreach ($data['suspectedMatches'] as $suspect) {
                    $sheet->setCellValueExplicit('A' . $rowIdx, $suspect['terminal_barcode'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $rowIdx, $suspect['store_barcode'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet->setCellValue('C' . $rowIdx, 'Şüpheli');
                    $sheet->setCellValue('D' . $rowIdx, 'Yakın eşleşme (Mesafe: ' . $suspect['distance'] . ')');
                    $rowIdx++;
                }
            }
            
            foreach (range('A', 'D') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
            
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="mutabakat_' . preg_replace('/[^a-zA-Z0-9]/', '_', $report['store_name']) . '.xlsx"');
            header('Cache-Control: max-age=0');
            
            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save('php://output');
            exit;
        }
    }
    
    header('HTTP/1.1 404 Not Found');
    echo 'Rapor bulunamadı veya veritabanı aktif değil.';
    exit;
}

// Get past reports if DB is enabled
/** @var array<array{
 *     id: int,
 *     tenant_id: int,
 *     store_name: string,
 *     terminal_count: int,
 *     store_count: int,
 *     matched_count: int,
 *     missing_count: int,
 *     extra_count: int,
 *     results_json: string,
 *     created_at: string
 * }> $pastReports */
$pastReports = [];
if ($dbEnabled) {
    $pastReports = $db->getAll(TenantContext::getTenantId());
}

$activeSubscription = $activeTenant !== null
    ? $subscriptionService->getCurrentSubscription($activeTenant->id)
    : null;
$allSaasPlans = SaasPlanCatalog::getAllPlans();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HBC Mutabakat - Lojistik & Perakende OCR Koli Mutabakatı (Multi-Tenant SaaS)</title>
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
                    <h1>HBC Mutabakat SaaS</h1>
                    <p>B2B Lojistik & Perakende Koli / İrsaliye OCR Mutabakat Platformu</p>
                </div>
            </div>
            <div class="system-status" style="align-items: center; flex-wrap: wrap;">
                <?php if ($activeTenant !== null && $activeSubscription !== null): ?>
                    <span class="status-badge status-active" id="headerTenantBadge">
                        🏢 <?= htmlspecialchars($activeTenant->companyName) ?>
                        (<?= $activeSubscription->isUnlimited() ? 'Sınırsız OCR' : ($activeSubscription->usedOcrCount . '/' . $activeSubscription->monthlyOcrQuota . ' OCR') ?>)
                    </span>
                <?php else: ?>
                    <a href="#saasPlansSection" class="status-badge status-active" style="text-decoration: none;">
                        🎁 14 Gün / 50 OCR Ücretsiz Deneme Başlat
                    </a>
                <?php endif; ?>
                <a href="#saasPlansSection" class="btn btn-sm btn-secondary" style="padding: 0.45rem 0.9rem; font-size: 0.82rem;">
                    🚀 SaaS Paketler & Üyelik
                </a>
                <?php if ($dbEnabled): ?>
                    <span class="status-badge status-active">
                        <span class="pulse-dot"></span> MySQL Aktif
                    </span>
                <?php else: ?>
                    <span class="status-badge status-inactive" title="SaaS abonelik katmanı yerel SQLite üzerinde aktif; geçmiş mutabakat arşivi için config.php'den MySQL açılabilir.">
                        ⚠️ MySQL Devre Dışı (SaaS SQLite Aktif)
                    </span>
                <?php endif; ?>
            </div>
        </header>

        <!-- SaaS Subscription & Quota Status Banner -->
        <section class="card saas-status-banner animate-fade-in" id="saasStatusBar" style="padding: 1.25rem 1.75rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <?php if ($activeTenant !== null && $activeSubscription !== null): ?>
                        <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                            <strong style="font-size: 1.05rem;"><?= htmlspecialchars($activeTenant->companyName) ?></strong>
                            <span class="badge badge-matched" id="saasBannerPlanCode"><?= htmlspecialchars(strtoupper($activeSubscription->planCode)) ?></span>
                            <span class="badge <?= $activeSubscription->status === 'pending_payment' ? 'badge-extra' : 'badge-matched' ?>" id="saasBannerStatus">
                                Durum: <?= htmlspecialchars($activeSubscription->status) ?>
                            </span>
                        </div>
                        <p class="text-muted" style="font-size: 0.88rem; margin-top: 0.35rem;" id="saasBannerQuotaText">
                            Aylık Koli/İrsaliye OCR Kotası:
                            <strong><?= $activeSubscription->usedOcrCount ?></strong> /
                            <strong><?= $activeSubscription->isUnlimited() ? 'Sınırsız' : (string)$activeSubscription->monthlyOcrQuota ?></strong>
                            <?php if ($activeSubscription->trialEndsAt !== null): ?>
                                | Deneme Bitiş: <strong><?= htmlspecialchars($activeSubscription->trialEndsAt) ?></strong>
                            <?php else: ?>
                                | Dönem Sonu: <strong><?= htmlspecialchars($activeSubscription->endsAt) ?></strong>
                            <?php endif; ?>
                        </p>
                    <?php else: ?>
                        <strong style="font-size: 1.05rem;">🎁 Lojistik & Perakende Tedarikçilerine Özel: 14 Gün / 50 Adet Koli & İrsaliye OCR Ücretsiz Deneme!</strong>
                        <p class="text-muted" style="font-size: 0.88rem; margin-top: 0.25rem;">
                            Hemen ücretsiz firma kaydınızı oluşturun, el terminali (Excel) ve mağaza irsaliye (PDF) mutabakatını saniyeler içinde deneyimleyin.
                        </p>
                    <?php endif; ?>
                </div>
                <div style="display: flex; gap: 0.6rem; flex-wrap: wrap;">
                    <a href="#saasRegisterBox" class="btn btn-sm btn-secondary">Firma Kaydı (14 Gün Ücretsiz)</a>
                    <a href="#saasPlansSection" class="btn btn-sm btn-primary" style="width: auto;">Paket Yükselt</a>
                </div>
            </div>
            <div id="saasQuotaAlertBox" class="mismatch-alert-box" style="display: none; margin-top: 1rem;"></div>
        </section>

        <!-- Main Panel Grid -->
        <main class="main-content">
            <!-- Upload Panel -->
            <section class="card upload-card animate-slide-up">
                <div class="card-header">
                    <h2>Mutabakat Başlat</h2>
                    <p>Excel/CSV dosyasını ve Mağaza PDF çıktısını yükleyerek eşleştirmeyi saniyeler içinde tamamlayın.</p>
                </div>
                
                <form id="reconcileForm" class="reconcile-form">
                    <input type="hidden" id="tenant_id" name="tenant_id" value="<?= $activeTenant !== null ? $activeTenant->id : '' ?>">
                    <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1.5rem;">
                        <div class="form-group" style="flex: 1; margin-bottom: 0;">
                            <label for="company_id">Firma</label>
                            <select id="company_id" name="company_id" required style="width: 100%; padding: 0.75rem; border-radius: 8px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); font-family: inherit; font-size: 0.95rem; outline: none; cursor: pointer; transition: border-color 0.2s;">
                                <option value="1" selected>LCW</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1; margin-bottom: 0;">
                            <label for="store_name">Araç Plakası <?= $dbEnabled ? '' : '<span class="optional-text">(İsteğe Bağlı)</span>' ?></label>
                            <input type="text" id="store_name" name="store_name" placeholder="Örn: 34ABC123" <?= $dbEnabled ? 'required' : '' ?> style="width: 100%;">
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

            <!-- Past Reports (If database active) -->
            <?php if ($dbEnabled): ?>
                <section class="card past-reports-card animate-slide-up">
                    <div class="card-header">
                        <h2>Kayıtlı Geçmiş Mutabakatlar</h2>
                        <p>Daha önce kaydedilmiş karşılaştırma sonuçlarını inceleyin ve dışa aktarın.</p>
                    </div>
                    <div class="table-wrapper">
                        <?php if (empty($pastReports)): ?>
                            <div class="empty-state">Henüz kayıtlı rapor bulunmuyor.</div>
                        <?php else: ?>
                            <table class="past-reports-table">
                                <thead>
                                    <tr>
                                        <th>Mağaza / Sevkiyat Adı</th>
                                        <th>Tarih</th>
                                        <th>Özet</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pastReports as $report): ?>
                                        <tr>
                                            <td class="font-semibold"><?= htmlspecialchars($report['store_name']) ?></td>
                                            <td class="text-muted">
                                                <?php
                                                $timestamp = strtotime($report['created_at']);
                                                echo htmlspecialchars($timestamp !== false ? date('d.m.Y H:i', $timestamp) : $report['created_at']);
                                                ?>
                                            </td>
                                            <td>
                                                <div class="mini-badges">
                                                    <span class="mini-badge badge-matched" title="Eşleşen"><?= $report['matched_count'] ?> ✅</span>
                                                    <span class="mini-badge badge-missing" title="Eksik"><?= $report['missing_count'] ?> ❌</span>
                                                    <span class="mini-badge badge-extra" title="Fazla"><?= $report['extra_count'] ?> ➕</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="row-actions">
                                                    <button type="button" class="btn btn-sm btn-view-report" data-id="<?= $report['id'] ?>">Raporu Yükle</button>
                                                    <a href="index.php?action=export-excel-saved&id=<?= $report['id'] ?>" class="btn btn-sm btn-export">Excel İndir</a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- 2026 Multi-Tenant SaaS Paket Seçimi, Ücretsiz Kayıt ve Paket Yükseltme Bölümü -->
            <section class="card saas-plans-card animate-slide-up" id="saasPlansSection" style="margin-top: 2rem;">
                <div class="card-header">
                    <h2>2026 B2B Lojistik & Perakende OCR Mutabakat SaaS Paketleri</h2>
                    <p>14 Gün / 50 OCR Ücretsiz Deneme ile hemen başlayın veya operasyon hacminize uygun pakete yükseltin (KDV Hariç).</p>
                </div>

                <!-- Pricing Cards Grid -->
                <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); margin-bottom: 2rem;">
                    <?php foreach ($allSaasPlans as $planCode => $plan): ?>
                        <div class="stat-box <?= $planCode === 'logistics_pro' ? 'stat-matched' : 'stat-extra' ?>" style="display: flex; flex-direction: column; justify-content: space-between; gap: 1rem;">
                            <div>
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                    <span class="badge <?= $planCode === 'logistics_pro' ? 'badge-matched' : 'badge-extra' ?>"><?= htmlspecialchars($plan['badge']) ?></span>
                                    <code style="font-size: 0.75rem; opacity: 0.75;"><?= htmlspecialchars($planCode) ?></code>
                                </div>
                                <div class="stat-label" style="font-size: 1.15rem; color: var(--text-primary);"><?= htmlspecialchars($plan['name']) ?></div>
                                <?php if ($plan['is_trial']): ?>
                                    <div class="stat-value" style="font-size: 1.7rem; margin: 0.5rem 0;">Ücretsiz <span style="font-size: 0.9rem; font-weight: 500;">(14 Gün / 50 OCR)</span></div>
                                <?php else: ?>
                                    <div class="stat-value" style="font-size: 1.65rem; margin: 0.5rem 0;">
                                        <?= number_format($plan['monthly_price_try'], 0, ',', '.') ?> ₺<span style="font-size: 0.85rem; font-weight: 500;">/ay</span>
                                    </div>
                                    <div style="font-size: 0.85rem; font-weight: 600; color: var(--primary); margin-bottom: 0.5rem;">
                                        Yıllık Peşin: <?= number_format($plan['yearly_price_try'], 0, ',', '.') ?> ₺ / Yıl
                                    </div>
                                <?php endif; ?>
                                <p class="stat-desc" style="margin-bottom: 0.75rem; line-height: 1.4;"><?= htmlspecialchars($plan['description']) ?></p>
                                <ul style="padding-left: 1.1rem; font-size: 0.82rem; color: var(--text-secondary); line-height: 1.55;">
                                    <?php foreach ($plan['features'] as $feat): ?>
                                        <li><?= htmlspecialchars($feat) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <div>
                                <?php if ($plan['is_trial']): ?>
                                    <a href="#saasRegisterBox" class="btn btn-sm btn-secondary" style="width: 100%;">Ücretsiz Deneme Başlat</a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-primary btn-select-saas-plan" data-plan="<?= htmlspecialchars($planCode) ?>" style="width: 100%;">
                                        Bu Pakete Yükselt
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Self-Service Registration & Plan Upgrade Forms -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
                    <!-- Self-Service Tenant Registration (14-Day / 50 OCR Trial) -->
                    <div class="stat-box" id="saasRegisterBox" style="background: rgba(255,255,255,0.6); border: 1px solid var(--border-color);">
                        <h3 style="font-size: 1.1rem; margin-bottom: 0.5rem;">🏢 Yeni Tedarikçi / Lojistik Firması Kaydı</h3>
                        <p class="text-muted" style="font-size: 0.85rem; margin-bottom: 1rem;">
                            Anında <strong>14 Gün ve 50 Adet Koli/İrsaliye OCR</strong> okuma kotalı ücretsiz deneme aboneliğinizi başlatır.
                        </p>
                        <form id="saasRegisterForm">
                            <div class="form-group" style="margin-bottom: 0.85rem;">
                                <label for="reg_company_name">Firma Ünvanı</label>
                                <input type="text" id="reg_company_name" name="company_name" placeholder="Örn: Pozitif Lojistik ve Tekstil A.Ş." required>
                            </div>
                            <div class="form-group" style="margin-bottom: 0.85rem;">
                                <label for="reg_tax_number">Vergi Numarası (Opsiyonel)</label>
                                <input type="text" id="reg_tax_number" name="tax_number" placeholder="Örn: 1234567890">
                            </div>
                            <div class="form-group" style="margin-bottom: 0.85rem;">
                                <label for="reg_contact_name">Yetkili Ad Soyad</label>
                                <input type="text" id="reg_contact_name" name="contact_name" placeholder="Örn: Ahmet Yılmaz" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 0.85rem;">
                                <label for="reg_contact_email">Kurumsal E-Posta</label>
                                <input type="text" id="reg_contact_email" name="contact_email" placeholder="ornek@firmaniz.com.tr" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 1rem;">
                                <label for="reg_contact_phone">Telefon (Opsiyonel)</label>
                                <input type="text" id="reg_contact_phone" name="contact_phone" placeholder="05XX XXX XX XX">
                            </div>
                            <button type="submit" class="btn btn-primary" id="saasRegisterSubmitBtn">
                                🎁 14 Gün / 50 OCR Ücretsiz Denemeyi Başlat
                            </button>
                            <div id="saasRegisterResult" style="display: none; margin-top: 0.75rem; font-size: 0.88rem;"></div>
                        </form>
                    </div>

                    <!-- Plan Upgrade Request (pending_payment) -->
                    <div class="stat-box" id="saasUpgradeBox" style="background: rgba(255,255,255,0.6); border: 1px solid var(--border-color);">
                        <h3 style="font-size: 1.1rem; margin-bottom: 0.5rem;">🚀 Paket Yükseltme Talebi (Havale/EFT & Kurumsal Lisans)</h3>
                        <p class="text-muted" style="font-size: 0.85rem; margin-bottom: 1rem;">
                            Seçtiğiniz paket <code>pending_payment</code> durumunda açılır; ödeme/havale onayıyla kotanız anında sıfırlanıp yükseltilir.
                        </p>
                        <form id="saasUpgradeForm">
                            <input type="hidden" id="upg_tenant_id" name="tenant_id" value="<?= $activeTenant !== null ? $activeTenant->id : '' ?>">
                            <div class="form-group" style="margin-bottom: 0.85rem;">
                                <label for="upg_plan_code">Yükseltilecek Paket</label>
                                <select id="upg_plan_code" name="plan_code" style="width: 100%; padding: 0.75rem; border-radius: 8px; border: 1px solid var(--border-color); background: #fff;">
                                    <option value="logistics_pro" selected>Lojistik & Perakende Pro (2.500 OCR/Ay — 4.990 ₺/Ay | 39.900 ₺/Yıl)</option>
                                    <option value="supplier_starter">Tedarikçi Başlangıç (500 OCR/Ay — 2.490 ₺/Ay | 24.900 ₺/Yıl)</option>
                                    <option value="enterprise_unlimited">Kurumsal Sınırsız / Özel ERP (Sınırsız OCR — 9.900 ₺/Ay | 89.900 ₺/Yıl)</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom: 0.85rem;">
                                <label for="upg_billing_cycle">Faturalama Dönemi</label>
                                <select id="upg_billing_cycle" name="billing_cycle" style="width: 100%; padding: 0.75rem; border-radius: 8px; border: 1px solid var(--border-color); background: #fff;">
                                    <option value="yearly" selected>Yıllık Peşin Lisans (%20-%33 Avantajlı + Ücretsiz Kurulum)</option>
                                    <option value="monthly">Aylık Abonelik</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom: 1rem;">
                                <label for="upg_payment_reference">Ödeme / Havale Dekont Referansı (Opsiyonel)</label>
                                <input type="text" id="upg_payment_reference" name="payment_reference" placeholder="Örn: EFT-20261015-LOJISTIKPRO">
                            </div>
                            <button type="submit" class="btn btn-primary" id="saasUpgradeSubmitBtn">
                                💳 Paket Yükseltme Talebi Oluştur (pending_payment)
                            </button>
                            <div id="saasUpgradeResult" style="display: none; margin-top: 0.75rem; font-size: 0.88rem;"></div>
                        </form>
                    </div>
                </div>
            </section>
        </main>

        <!-- Footer -->
        <footer class="app-footer" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <p>&copy; <?= date('Y') ?> HBC Mutabakat SaaS — Global Pozitif Teknolojiler.</p>
            <p style="font-size: 0.8rem; color: var(--text-muted);">Aktif Sunucu IP: <strong><?= $_SERVER['SERVER_ADDR'] ?? 'Bilinmiyor' ?></strong></p>
        </footer>
    </div>

    <!-- Application Script -->
    <script src="assets/js/app.js?v=<?= time() ?>"></script>
</body>
</html>
