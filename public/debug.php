<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\PdfExtractor;

// Limit memory & execution time for heavy OCR tasks
@ini_set('memory_limit', '1024M');
@set_time_limit(300);

$config = require dirname(__DIR__) . '/config.php';

$textRaw = '';
$ocrRaw = '';
$error = '';
$fileName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file'])) {
    $file = $_FILES['pdf_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $pdfPath = $file['tmp_name'];
        $fileName = $file['name'];

        try {
            $pdfExtractor = new PdfExtractor();

            // Extract Text Mode Raw Output
            $textRaw = $pdfExtractor->extractRawText($pdfPath, 'text');

            // Extract OCR Mode Raw Output
            $ocrRaw = $pdfExtractor->extractRawText($pdfPath, 'ocr');
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    } else {
        $error = 'Dosya yüklenirken bir hata oluştu.';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF Ham Çıktı Analiz Paneli (Debug) - HBC Mutabakat</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Fira+Code:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0B0F19;
            --card-bg: rgba(22, 30, 49, 0.7);
            --border-color: rgba(255, 255, 255, 0.08);
            --text-primary: #F3F4F6;
            --text-secondary: #9CA3AF;
            --primary: #8B5CF6;
            --primary-hover: #7C3AED;
            --secondary: #10B981;
            --danger: #EF4444;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-primary);
            min-height: 100vh;
            padding: 2rem;
            overflow-x: hidden;
            background-image: radial-gradient(circle at 10% 20%, rgba(139, 92, 246, 0.15) 0%, transparent 40%),
                              radial-gradient(circle at 90% 80%, rgba(16, 185, 129, 0.1) 0%, transparent 40%);
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 1.5rem;
        }

        .logo h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, #A78BFA, #34D399);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logo p {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.2s;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .upload-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(12px);
            border-radius: 16px;
            padding: 2rem;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            position: relative;
        }

        .file-input-container {
            width: 100%;
            max-width: 500px;
            border: 2px dashed rgba(139, 92, 246, 0.3);
            border-radius: 12px;
            padding: 2rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .file-input-container:hover {
            border-color: var(--primary);
            background: rgba(139, 92, 246, 0.05);
        }

        .file-input-container input {
            display: none;
        }

        .file-input-container label {
            cursor: pointer;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .file-input-container span.icon {
            font-size: 2.5rem;
        }

        .file-input-container span.text {
            font-weight: 600;
        }

        .file-input-container span.info {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .btn-submit {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.8rem 2rem;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn-submit:hover {
            background: var(--primary-hover);
        }

        .loader-overlay {
            display: none;
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(11, 15, 25, 0.9);
            border-radius: 16px;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            gap: 1.5rem;
            z-index: 10;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid rgba(255, 255, 255, 0.1);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .error-box {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #FCA5A5;
            padding: 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .results-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }

        @media (max-width: 1024px) {
            .results-grid {
                grid-template-columns: 1fr;
            }
        }

        .output-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            display: flex;
            flex-direction: column;
            height: 70vh;
            overflow: hidden;
        }

        .output-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255, 255, 255, 0.02);
        }

        .output-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
        }

        .output-title .badge {
            font-size: 0.75rem;
            padding: 0.2rem 0.5rem;
            border-radius: 9999px;
            background: rgba(139, 92, 246, 0.2);
            color: #C084FC;
        }

        .output-title .badge.ocr {
            background: rgba(16, 185, 129, 0.2);
            color: #34D399;
        }

        .copy-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .copy-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .output-body {
            flex: 1;
            overflow: auto;
            padding: 1.5rem;
            background: #060913;
        }

        pre {
            font-family: 'Fira Code', monospace;
            font-size: 0.9rem;
            line-height: 1.6;
            color: #E5E7EB;
            white-space: pre-wrap;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header>
            <div class="logo">
                <h1>PDF Ham Çıktı Analizi</h1>
                <p>OCR ve Metin modlarının PDF içeriklerini nasıl okuduğunu doğrudan yan yana inceleyin.</p>
            </div>
            <a href="index.php" class="back-btn">← Mutabakat Ekranına Dön</a>
        </header>

        <!-- Upload Form -->
        <section class="upload-section">
            <form id="uploadForm" action="debug.php" method="POST" enctype="multipart/form-data" style="width: 100%; display: flex; flex-direction: column; align-items: center; gap: 1.5rem;">
                <div class="file-input-container" onclick="document.getElementById('pdf_file').click()">
                    <input type="file" id="pdf_file" name="pdf_file" accept=".pdf" required onchange="updateFileInfo(this)">
                    <label>
                        <span class="icon">📕</span>
                        <span class="text" id="file_label_text">Bir PDF Dosyası Seçin</span>
                        <span class="info">Sürükle-bırak veya tıklayarak seç</span>
                    </label>
                </div>
                <button type="submit" class="btn-submit">Ham Çıktıyı Çıkar ve Karşılaştır</button>
            </form>

            <!-- Loading overlay -->
            <div class="loader-overlay" id="loaderOverlay">
                <div class="spinner"></div>
                <h3 style="color: var(--text-primary);">Ham Veriler Çıkarılıyor...</h3>
                <p style="color: var(--text-secondary); max-width: 300px; font-size: 0.85rem;">Tesseract OCR işlemi PDF sayfa sayısına bağlı olarak biraz zaman alabilir.</p>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="error-box">
                <strong>Hata:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($textRaw !== '' || $ocrRaw !== ''): ?>
            <!-- Results Grid -->
            <div class="results-grid">
                <!-- Left Panel: Text Mode -->
                <div class="output-card">
                    <div class="output-header">
                        <div class="output-title">
                            📄 Metin Modu Çıktısı <span class="badge">pdftotext</span>
                        </div>
                        <button class="copy-btn" onclick="copyToClipboard('textOutput')">Metni Kopyala 📋</button>
                    </div>
                    <div class="output-body">
                        <pre id="textOutput"><?= htmlspecialchars($textRaw !== '' ? $textRaw : 'Herhangi bir metin çıkarılamadı.') ?></pre>
                    </div>
                </div>

                <!-- Right Panel: OCR Mode -->
                <div class="output-card">
                    <div class="output-header">
                        <div class="output-title">
                            📷 Görsel OCR Modu Çıktısı <span class="badge ocr">Tesseract</span>
                        </div>
                        <button class="copy-btn" onclick="copyToClipboard('ocrOutput')">Metni Kopyala 📋</button>
                    </div>
                    <div class="output-body">
                        <pre id="ocrOutput"><?= htmlspecialchars($ocrRaw !== '' ? $ocrRaw : 'Herhangi bir OCR metni çıkarılamadı.') ?></pre>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function updateFileInfo(input) {
            const labelText = document.getElementById('file_label_text');
            if (input.files && input.files.length > 0) {
                labelText.textContent = input.files[0].name;
            } else {
                labelText.textContent = 'Bir PDF Dosyası Seçin';
            }
        }

        document.getElementById('uploadForm').addEventListener('submit', () => {
            document.getElementById('loaderOverlay').style.display = 'flex';
        });

        function copyToClipboard(elementId) {
            const text = document.getElementById(elementId).innerText;
            navigator.clipboard.writeText(text).then(() => {
                alert('Metin başarıyla panoya kopyalandı.');
            }).catch(err => {
                console.error('Kopyalama hatası: ', err);
            });
        }
    </script>
</body>
</html>
