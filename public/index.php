<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Database;
use App\ExcelExtractor;
use App\PdfExtractor;
use App\Reconciler;

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

$action = $_GET['action'] ?? '';

// Handle Reconcile Request via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reconcile') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $storeName = trim((string)($_POST['store_name'] ?? ''));
        if ($storeName === '') {
            $storeName = 'Sevkiyat - ' . date('d.m.Y H:i');
        }
        
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
        if ($pdfFile['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'PDF dosyası yüklenirken hata oluştu.']);
            exit;
        }

        $excelPath = $excelFile['tmp_name'];
        $pdfPath = $pdfFile['tmp_name'];

        $excelExtractor = new ExcelExtractor();
        $pdfExtractor = new PdfExtractor();
        $reconciler = new Reconciler($excelExtractor, $pdfExtractor);

        $result = $reconciler->reconcile($excelPath, $pdfPath);

        $savedId = null;
        if ($dbEnabled) {
            $savedId = $db->save($storeName, $result);
        }

        echo json_encode([
            'success' => true,
            'store_name' => $storeName,
            'saved_id' => $savedId,
            'result' => $result->toArray(),
        ]);
        exit;
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Handle Get Report Request via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get-report') {
    header('Content-Type: application/json; charset=utf-8');
    
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id > 0 && $dbEnabled) {
        $report = $db->getById($id);
        if ($report !== null) {
            /** @var array{
             *     matched: array<string>,
             *     missingInStore: array<string>,
             *     extraInStore: array<string>
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

// Handle Export CSV for saved reports
if ($action === 'export-csv') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id > 0 && $dbEnabled) {
        $report = $db->getById($id);
        if ($report !== null) {
            /** @var array{
             *     matched: array<string>,
             *     missingInStore: array<string>,
             *     extraInStore: array<string>
             * } $data */
            $data = json_decode($report['results_json'], true);
            
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="mutabakat_' . preg_replace('/[^a-zA-Z0-9]/', '_', $report['store_name']) . '.csv"');
            
            $output = fopen('php://output', 'w');
            if ($output !== false) {
                // BOM for Excel UTF-8 support
                fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
                
                fputcsv($output, ['Barkod / Takip No', 'Durum']);
                
                foreach ($data['missingInStore'] as $barcode) {
                    fputcsv($output, [$barcode, 'Eksik (Terminalde var, PDFte yok)']);
                }
                foreach ($data['extraInStore'] as $barcode) {
                    fputcsv($output, [$barcode, 'Fazla (PDFte var, Terminalde yok)']);
                }
                foreach ($data['matched'] as $barcode) {
                    fputcsv($output, [$barcode, 'Eşleşti']);
                }
                fclose($output);
            }
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
    $pastReports = $db->getAll();
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
    <link rel="stylesheet" href="assets/css/style.css">
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
            <div class="system-status">
                <?php if ($dbEnabled): ?>
                    <span class="status-badge status-active">
                        <span class="pulse-dot"></span> MySQL Aktif
                    </span>
                <?php else: ?>
                    <span class="status-badge status-inactive" title="Raporlar veritabanına kaydedilmeyecektir. config.php üzerinden aktif edebilirsiniz.">
                        ⚠️ MySQL Devre Dışı
                    </span>
                <?php endif; ?>
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
                    <div class="form-group">
                        <label for="store_name">Mağaza / Sevkiyat Adı</label>
                        <input type="text" id="store_name" name="store_name" placeholder="Örn: Kadıköy Mağazası - Sevkiyat 24" required>
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
                            <input type="file" id="pdf_file" name="pdf_file" accept=".pdf" required hidden>
                            <div class="dropzone-content">
                                <div class="dropzone-icon">📕</div>
                                <h3>Mağaza PDF Çıktısı</h3>
                                <p>Sürükle-bırak veya tıklayarak seç</p>
                                <span class="file-spec">Sadece .pdf formatı</span>
                            </div>
                            <div class="selected-file-info" style="display:none;">
                                <span class="file-name"></span>
                                <button type="button" class="remove-file-btn">&times;</button>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <span class="btn-text">Karşılaştırmayı Çalıştır</span>
                        <div class="loader" style="display:none;"></div>
                    </button>
                </form>
            </section>

            <!-- Results Section (Initially Hidden) -->
            <section class="card results-card" id="resultsSection" style="display:none;">
                <div class="results-header">
                    <div>
                        <h2 id="resultStoreName">Mutabakat Sonuçları</h2>
                        <p class="timestamp">İşlem anlık olarak tamamlandı.</p>
                    </div>
                    <div class="action-buttons">
                        <button type="button" class="btn btn-secondary" id="downloadCsvBtn">CSV İndir</button>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-box stat-matched">
                        <div class="stat-value" id="statMatchedVal">0</div>
                        <div class="stat-label">Tam Eşleşen Koliler</div>
                        <div class="stat-desc">Her iki listede de olanlar</div>
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
                </div>

                <!-- Filter & Search Bar -->
                <div class="filter-search-bar">
                    <div class="filter-tabs">
                        <button class="tab-btn active" data-filter="all">Tümü (<span id="countAll">0</span>)</button>
                        <button class="tab-btn tab-missing" data-filter="missing">Eksikler (<span id="countMissing">0</span>)</button>
                        <button class="tab-btn tab-extra" data-filter="extra">Fazlalar (<span id="countExtra">0</span>)</button>
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
                                <th>Barkod / Takip Numarası</th>
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
                                                    <a href="index.php?action=export-csv&id=<?= $report['id'] ?>" class="btn btn-sm btn-export">CSV İndir</a>
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
        </main>

        <!-- Footer -->
        <footer class="app-footer">
            <p>&copy; <?= date('Y') ?> HBC Mutabakat.</p>
        </footer>
    </div>

    <!-- Application Script -->
    <script src="assets/js/app.js"></script>
</body>
</html>
