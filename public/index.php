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

        $result = $reconciler->reconcile($excelPath, $pdfPaths);

        // Generate barcode to store mapping
        $excelMap = $excelExtractor->extractMap($excelPath);
        $barcodeStores = $excelMap;
        foreach ($pdfPaths as $path) {
            $pdfStoreName = $pdfExtractor->extractStoreName($path);
            $pdfBarcodes = $pdfExtractor->extract($path);
            foreach ($pdfBarcodes as $barcode) {
                if (!isset($barcodeStores[$barcode])) {
                    $barcodeStores[$barcode] = $pdfStoreName;
                }
            }
        }

        $savedId = null;
        if ($dbEnabled) {
            $dbStart = microtime(true);
            $savedId = $db->save($storeName, $result);
            $dbElapsed = round(microtime(true) - $dbStart, 4);
            \App\Logger::log("Veritabanı kayıt işlemi tamamlandı - Süre: {$dbElapsed} saniye (ID: {$savedId})");
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
        ]);
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
        $report = $db->getById($id);
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
        $report = $db->getById($id);
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
                        <label for="store_name">Araç Plakası <?= $dbEnabled ? '' : '<span class="optional-text">(İsteğe Bağlı)</span>' ?></label>
                        <input type="text" id="store_name" name="store_name" placeholder="Örn: 34ABC123" <?= $dbEnabled ? 'required' : '' ?>>
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
                <div class="stats-grid">
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
                </div>

                <!-- Filter & Search Bar -->
                <div class="filter-search-bar">
                    <div class="filter-tabs">
                        <button class="tab-btn active" data-filter="all">Tümü (<span id="countAll">0</span>)</button>
                        <button class="tab-btn tab-missing" data-filter="missing">Eksikler (<span id="countMissing">0</span>)</button>
                        <button class="tab-btn tab-extra" data-filter="extra">Fazlalar (<span id="countExtra">0</span>)</button>
                        <button class="tab-btn tab-suspected" data-filter="suspected">Şüpheli (<span id="countSuspected">0</span>)</button>
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
