<?php

declare(strict_types=1);

namespace App;

use Smalot\PdfParser\Parser;
use thiagoalessio\TesseractOCR\TesseractOCR;

class PdfExtractor
{
    /** @var array<string, string> */
    private array $barcodeToOriginalMap = [];

    /**
     * @var array<array{line_number: int, line_text: string, detected_barcodes: array<string>}>
     */
    private array $mismatches = [];

    /**
     * Son extract() çağrısının satır meta verileri (Python TSV çıktısından).
     * Dönen satır listesiyle aynı sırada ve aynı uzunluktadır; meta üretilemediyse boştur.
     *
     * @var array<int, array{conf: float|null, alts: array<string>}>
     */
    private array $lastLineMeta = [];

    private bool $useOcr = true;

    /** Maximum seconds to wait for the Python subprocess before terminating it. */
    private const PYTHON_TIMEOUT_SECONDS = 300;

    /** OCR sonuç önbelleğinin saklandığı dizin (PDF içeriği sha1 anahtarıyla). */
    private const CACHE_SUBDIR = '/var/cache';

    public function setUseOcr(bool $useOcr): void
    {
        $this->useOcr = $useOcr;
    }

    public function isUseOcr(): bool
    {
        return $this->useOcr;
    }

    /**
     * Retrieves the map of extracted clean barcodes to their original raw words in PDF.
     *
     * @return array<string, string>
     */
    public function getBarcodeToOriginalMap(): array
    {
        return $this->barcodeToOriginalMap;
    }

    /**
     * Retrieves the list of detected barcode mismatches on a single PDF line.
     *
     * @return array<array{line_number: int, line_text: string, detected_barcodes: array<string>}>
     */
    public function getMismatches(): array
    {
        return $this->mismatches;
    }

    /**
     * Son extract() çağrısının satır meta verilerini döner (satırlarla aynı sıra/uzunluk).
     * "alts": aynı satırın ikinci geçiş/hakem motorundan gelen alternatif okumaları;
     * "conf": Tesseract'ın satırdaki en düşük kelime güveni (0-100, bilinmiyorsa null).
     *
     * @return array<int, array{conf: float|null, alts: array<string>}>
     */
    public function getLastLineMeta(): array
    {
        return $this->lastLineMeta;
    }

    /**
     * Extracts barcode/tracking numbers from a PDF file.
     *
     * @param string $filePath
     * @return array<string>
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function extract(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("PDF dosyası bulunamadı: {$filePath}");
        }

        // PHP fallback yollarında meta üretilmez; bayat meta sızmasın diye baştan sıfırla.
        $this->lastLineMeta = [];

        // Try Python extraction first
        $mode = $this->useOcr ? 'ocr' : 'text';
        $pythonResult = $this->extractWithPython($filePath, $mode);
        if ($pythonResult !== null) {
            return $pythonResult;
        }

        // Fallback to optimized native PHP extraction
        if ($this->useOcr) {
            return $this->extractOcrPhp($filePath);
        }

        $pdfStart = microtime(true);
        \App\Logger::log("[PdfExtractor-Text] PDF okuma başladı (PHP Fallback): " . basename($filePath));

        $text = '';
        $usedPdftotext = false;

        $checkCommand = PHP_OS_FAMILY === 'Windows' ? 'where pdftotext' : 'which pdftotext';
        $hasPdftotext = (string)shell_exec($checkCommand);

        if (trim($hasPdftotext) !== '') {
            $pdftotextStart = microtime(true);
            $output = shell_exec('pdftotext -layout ' . escapeshellarg($filePath) . ' -');
            if ($output !== null && $output !== false) {
                $text = (string)$output;
                $usedPdftotext = true;
                $pdftotextElapsed = round(microtime(true) - $pdftotextStart, 4);
                \App\Logger::log("[PdfExtractor-Text] C++ pdftotext aracı kullanıldı - Süre: {$pdftotextElapsed} saniye");
            }
        }

        if (!$usedPdftotext) {
            \App\Logger::log("[PdfExtractor-Text] UYARI: pdftotext bulunamadı, Smalot PDF Parser fallback devreye giriyor.");
            $smalotStart = microtime(true);
            $parser = new Parser();
            try {
                $pdf = $parser->parseFile($filePath);
                $text = $pdf->getText();
                $smalotElapsed = round(microtime(true) - $smalotStart, 4);
                \App\Logger::log("[PdfExtractor-Text] Smalot PDF Parser kullanıldı - Süre: {$smalotElapsed} saniye");
            } catch (\Exception $e) {
                \App\Logger::log("[PdfExtractor-Text] Smalot HATA: " . $e->getMessage());
                throw new \RuntimeException("PDF dosyası ayrıştırılamadı: " . $e->getMessage());
            }
        }

        $barcodes = $this->processText($text);
        $elapsed = round(microtime(true) - $pdfStart, 4);
        \App\Logger::log("[PdfExtractor-Text] Tamamlandı - Süre: {$elapsed} saniye | Benzersiz Barkod: " . count($barcodes));

        return $barcodes;
    }

    /**
     * Extracts store name from PDF file.
     *
     * @param string $filePath PDF içeriğinin okunacağı (yüklemede geçici) dosya yolu.
     * @param string|null $originalName Orijinal dosya adı (örn. "T410.pdf"). Mağaza kodunu
     *     buradan alırız; aksi halde sunucudaki "phpXXXX" geçici adı mağaza adına sızar.
     * @return string
     */
    public function extractStoreName(string $filePath, ?string $originalName = null): string
    {
        if (!file_exists($filePath)) {
            return 'Bilinmeyen Mağaza';
        }

        $parser = new Parser();
        try {
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText();
        } catch (\Exception $e) {
            return 'Bilinmeyen Mağaza';
        }

        $lines = explode("\n", $text);

        $filename = basename($originalName ?? $filePath, '.pdf');
        $storeCodeDigits = '';
        if (preg_match('/\d+/', $filename, $matches)) {
            $storeCodeDigits = $matches[0];
        }

        if ($storeCodeDigits !== '') {
            $codePattern = '';
            for ($i = 0; $i < strlen($storeCodeDigits); $i++) {
                $char = $storeCodeDigits[$i];
                if ($char === '0') {
                    $codePattern .= '[0oO]';
                } else {
                    $codePattern .= $char;
                }
            }

            $regex = '/(?:[Tt1lIı]|)\s*' . $codePattern . '\s*[-_:]+\s*(.+)/i';
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match($regex, $line, $matches)) {
                    $name = trim($matches[1]);
                    if (strlen($name) > 3) {
                        $name = strtr($name, [
                            '|s' => 'IS',
                            '|S' => 'IS',
                            '|' => 'I',
                        ]);
                        return $filename . ' - ' . $name;
                    }
                }
            }

            $fallbackRegex = '/(?:[Tt1lIı]|)\s*' . $codePattern . '\b\s*(.+)/i';
            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match($fallbackRegex, $line, $matches)) {
                    $name = trim($matches[1]);
                    if (strlen($name) > 3 && !preg_match('/(mutabakat|tarih|belge|numara|rapor)/iu', $name)) {
                        $name = strtr($name, [
                            '|s' => 'IS',
                            '|S' => 'IS',
                            '|' => 'I',
                        ]);
                        return $filename . ' - ' . $name;
                    }
                }
            }
        }

        $foundStoreLabel = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($foundStoreLabel) {
                if (preg_match('/(mutabakat|tarih|belge|numara|rapor)/iu', $line)) {
                    continue;
                }
                
                $cleanedStore = strtr($line, [
                    'T3o&lsT' => 'T308',
                    'o' => '0',
                    'O' => '0',
                    'l' => '1',
                    '|s' => 'IS',
                    '|S' => 'IS',
                    '|' => 'I',
                ]);
                if (strlen($cleanedStore) > 3 && !preg_match('/^[^\w\s]+$/', $cleanedStore)) {
                    return $cleanedStore;
                }
            }

            if (mb_stripos($line, 'Mağaza') !== false && mb_stripos($line, 'Müdür') === false && mb_stripos($line, 'Müd') === false) {
                $foundStoreLabel = true;
            }
        }

        if (preg_match('/^T\d+$/i', $filename)) {
            return strtoupper($filename);
        }

        return 'Bilinmeyen Mağaza';
    }

    /**
     * Builds a map of cleaned barcode (digits only) => store name for a single PDF.
     *
     * The store name is resolved once per PDF via {@see extractStoreName()}. Each PDF line
     * is then split into words and the digit-only candidate of each word (matching the same
     * per-word logic the Reconciler uses to detect "extra" barcodes) is mapped to that store.
     * This is what lets the "Fazla Koliler" (extra) rows display the correct store, since those
     * barcodes only exist in the PDF and never in the Excel/terminal list.
     *
     * @param string $filePath
     * @param string|null $originalName Orijinal dosya adı (örn. "T410.pdf"); mağaza adının
     *     geçici "phpXXXX" dosya adından türememesi için {@see extractStoreName()}'e geçilir.
     * @return array<string, string> Cleaned barcode => store name.
     */
    public function extractBarcodeStoreMap(string $filePath, ?string $originalName = null): array
    {
        $storeName = $this->extractStoreName($filePath, $originalName);
        $lines = $this->extract($filePath);

        $map = [];
        foreach ($lines as $line) {
            $words = preg_split('/\s+/', trim($line));
            if ($words === false) {
                $words = [$line];
            }

            foreach ($words as $word) {
                $digits = preg_replace('/\D/', '', $word);
                if (!is_string($digits)) {
                    continue;
                }
                $len = strlen($digits);
                if ($len < 10 || $len > 25) {
                    continue;
                }
                if (!isset($map[$digits])) {
                    $map[$digits] = $storeName;
                }
            }
        }

        return $map;
    }

    /**
     * Tries to extract barcodes using the Python backend.
     * Returns null if Python is not available or fails, allowing PHP fallback.
     *
     * @param string $filePath
     * @param string $mode
     * @return array<string>|null
     */
    private function extractWithPython(string $filePath, string $mode): ?array
    {
        $pythonScript = dirname(__DIR__) . '/src/reconcile.py';
        if (!file_exists($pythonScript)) {
            return null;
        }

        // Önbellek: aynı PDF (içerik hash'i) daha önce aynı modda okunduysa Python'u
        // hiç çalıştırma. extractBarcodeStoreMap() aynı PDF'i ikinci kez okuduğu için
        // bu, tek mutabakat isteği içinde bile OCR süresini yarıya indirir.
        $cacheFile = $this->getCacheFilePath($filePath, $mode, false);
        $cached = $this->readCache($cacheFile);
        if ($cached !== null) {
            \App\Logger::log("[PdfExtractor-Python] Önbellekten okundu - Mod: {$mode} | " . basename($filePath));
            $this->barcodeToOriginalMap = [];
            $this->mismatches = [];
            $this->lastLineMeta = $this->parseLineMeta($cached);
            return $cached['lines'] ?? [];
        }

        $cmd = $this->buildPythonCommand($pythonScript, $mode, $filePath, false);

        $result = $this->runProcessWithTimeout($cmd, 'PdfExtractor-Python-Live');
        if ($result === null) {
            \App\Logger::log("[PdfExtractor-Python] HATA: Süreç başlatılamadı.");
            return null;
        }

        if ($result['timed_out']) {
            \App\Logger::log("[PdfExtractor-Python] HATA: İşlem " . self::PYTHON_TIMEOUT_SECONDS . " saniye içinde tamamlanmadı, sonlandırıldı.");
            return null;
        }

        $data = json_decode(trim($result['stdout']), true);
        if (is_array($data) && isset($data['success']) && $data['success'] === true) {
            $this->barcodeToOriginalMap = [];
            $this->mismatches = [];
            $this->lastLineMeta = $this->parseLineMeta($data);

            $timeLog = isset($data['elapsed_time']) ? "Süre: {$data['elapsed_time']} sn" : "";
            \App\Logger::log("[PdfExtractor-Python] Başarıyla tamamlandı - Mod: {$mode} | {$timeLog}");

            $this->writeCache($cacheFile, $data);

            return $data['lines'] ?? [];
        }

        \App\Logger::log("[PdfExtractor-Python] Hata veya uyumsuz çıktı: " . trim(substr($result['stdout'], 0, 200)));
        return null;
    }

    /**
     * Python komutunu oluşturur. Linux'ta OCR, web isteklerini ve aynı sunucudaki
     * pozitif-photo uygulamasını boğmasın diye düşük CPU önceliğiyle (nice) çalıştırılır.
     */
    private function buildPythonCommand(string $pythonScript, string $mode, string $filePath, bool $raw): string
    {
        $pythonExecutable = 'python3';
        if (PHP_OS_FAMILY === 'Windows') {
            $pythonExecutable = 'python';
        } else {
            $hasPython3 = (string)shell_exec('which python3 2>/dev/null');
            if (trim($hasPython3) === '') {
                $pythonExecutable = 'python';
            }
        }

        $prefix = PHP_OS_FAMILY === 'Windows' ? '' : 'nice -n 10 ';
        $rawFlag = $raw ? ' --raw' : '';

        return $prefix . $pythonExecutable . ' ' . escapeshellarg($pythonScript)
            . $rawFlag . ' --mode ' . escapeshellarg($mode) . ' --pdf ' . escapeshellarg($filePath);
    }

    /**
     * Python çıktısındaki lines_meta alanını doğrulayıp normalize eder.
     * Satır sayısıyla uyuşmuyorsa (eski script, bozuk çıktı) boş döner.
     *
     * @param array<string, mixed> $data
     * @return array<int, array{conf: float|null, alts: array<string>}>
     */
    private function parseLineMeta(array $data): array
    {
        $lines = $data['lines'] ?? null;
        $meta = $data['lines_meta'] ?? null;
        if (!is_array($lines) || !is_array($meta) || count($meta) !== count($lines)) {
            return [];
        }

        $normalized = [];
        foreach (array_values($meta) as $entry) {
            $conf = null;
            $alts = [];
            if (is_array($entry)) {
                if (isset($entry['conf']) && is_numeric($entry['conf'])) {
                    $conf = (float)$entry['conf'];
                }
                if (isset($entry['alts']) && is_array($entry['alts'])) {
                    foreach ($entry['alts'] as $alt) {
                        if (is_string($alt) && $alt !== '') {
                            $alts[] = $alt;
                        }
                    }
                }
            }
            $normalized[] = ['conf' => $conf, 'alts' => $alts];
        }

        return $normalized;
    }

    /**
     * Önbellek dosya yolunu üretir. Anahtar; PDF içeriği, mod ve script sürümünden
     * (mtime) oluşur — script her güncellendiğinde eski önbellek doğal olarak düşer.
     */
    private function getCacheFilePath(string $filePath, string $mode, bool $raw): ?string
    {
        $hash = @sha1_file($filePath);
        if ($hash === false) {
            return null;
        }

        $cacheDir = dirname(__DIR__) . self::CACHE_SUBDIR;
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0750, true) && !is_dir($cacheDir)) {
            return null;
        }

        $scriptVersion = (string)@filemtime(dirname(__DIR__) . '/src/reconcile.py');

        return $cacheDir . '/ocr_' . $hash . '_' . $mode . ($raw ? '_raw' : '') . '_'
            . substr(sha1($scriptVersion), 0, 8) . '.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(?string $cacheFile): ?array
    {
        if ($cacheFile === null || !is_file($cacheFile)) {
            return null;
        }
        $content = @file_get_contents($cacheFile);
        if ($content === false) {
            return null;
        }
        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['success']) || $data['success'] !== true) {
            return null;
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeCache(?string $cacheFile, array $data): void
    {
        if ($cacheFile === null) {
            return;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            @file_put_contents($cacheFile, $json, LOCK_EX);
        }
    }

    /**
     * Runs a shell command via proc_open, streaming stdout/stderr non-blockingly,
     * and enforcing an overall timeout to avoid hanging indefinitely on a stuck subprocess.
     *
     * @param string $cmd
     * @param string $liveLogPrefix Log tag used for lines containing "OCR_PROGRESS:"
     * @return array{stdout: string, stderr: string, timed_out: bool}|null Null if the process could not be started.
     */
    private function runProcessWithTimeout(string $cmd, string $liveLogPrefix): ?array
    {
        $descriptorspec = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"]  // stderr
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdoutData = '';
        $stderrData = '';
        $timedOut = false;
        $startTime = microtime(true);

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            // Wait up to 200ms
            $numChanged = stream_select($read, $write, $except, 0, 200000);

            if ($numChanged === false) {
                break;
            }

            $hasRead = false;
            if ($numChanged > 0) {
                foreach ($read as $stream) {
                    if ($stream === $pipes[1]) {
                        $chunk = fread($pipes[1], 8192);
                        if ($chunk !== false && $chunk !== '') {
                            $stdoutData .= $chunk;
                            $hasRead = true;
                        }
                    } elseif ($stream === $pipes[2]) {
                        $chunk = fread($pipes[2], 8192);
                        if ($chunk !== false && $chunk !== '') {
                            $stderrData .= $chunk;
                            $hasRead = true;
                            // Parse live stderr and log to app.log instantly
                            $lines = explode("\n", $chunk);
                            foreach ($lines as $line) {
                                $line = trim($line);
                                if ($line !== '') {
                                    if (str_contains($line, 'OCR_PROGRESS:')) {
                                        $cleanLog = str_replace('OCR_PROGRESS:', '', $line);
                                        \App\Logger::log("[{$liveLogPrefix}]" . $cleanLog);
                                    } else {
                                        \App\Logger::log("[{$liveLogPrefix}-Stderr] " . $line);
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                // Read remaining outputs one last time
                while (($chunk = fread($pipes[1], 8192)) !== '' && $chunk !== false) {
                    $stdoutData .= $chunk;
                }
                while (($chunk = fread($pipes[2], 8192)) !== '' && $chunk !== false) {
                    $stderrData .= $chunk;
                }
                break;
            }

            if ((microtime(true) - $startTime) > self::PYTHON_TIMEOUT_SECONDS) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }

            if ($numChanged === 0 && !$hasRead) {
                usleep(10000); // 10ms CPU sleep
            }
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return ['stdout' => $stdoutData, 'stderr' => $stderrData, 'timed_out' => $timedOut];
    }

    /**
     * Extracts barcode/tracking numbers from a PDF file using native PHP OCR (Imagick + Tesseract).
     *
     * @param string $filePath
     * @return array<string>
     * @throws \RuntimeException
     */
    private function extractOcrPhp(string $filePath): array
    {
        if (!class_exists('\Imagick')) {
            throw new \RuntimeException("PDF görsel dönüşümü için 'Imagick' PHP eklentisi kurulu olmalıdır.");
        }

        $ocrStart = microtime(true);
        \App\Logger::log("[PdfExtractor-OCR] OCR işlemi başladı (PHP Fallback): " . basename($filePath));

        $images = [];
        $tempDir = dirname(__DIR__) . '/var/tmp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0750, true);
        }

        try {
            $pingImagick = new \Imagick();
            $pingImagick->pingImage($filePath);
            $pageCount = $pingImagick->getNumberImages();
            $pingImagick->clear();
            $pingImagick->destroy();

            \App\Logger::log("[PdfExtractor-OCR] Toplam Sayfa Sayısı: {$pageCount}");

            for ($i = 0; $i < $pageCount; $i++) {
                $pageStart = microtime(true);
                echo " ";
                if (function_exists('ob_flush') && ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();

                $pageImagick = new \Imagick();
                $pageImagick->setResolution(300, 300); // Optimized resolution (300 DPI)
                $pageImagick->readImage($filePath . '[' . $i . ']');

                $pageImagick->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
                // Enhance contrast before thresholding
                // @phpstan-ignore-next-line
                $pageImagick->levelImage(0.25 * \Imagick::getQuantum(), 1.0, 0.75 * \Imagick::getQuantum());
                $pageImagick->thresholdImage(0.5 * \Imagick::getQuantum());
                $pageImagick->setImageFormat('png');

                $pagePath = $tempDir . '/page_' . uniqid() . '_' . $i . '.png';
                $pageImagick->writeImage($pagePath);
                $images[] = $pagePath;

                $pageImagick->clear();
                $pageImagick->destroy();

                $pageElapsed = round(microtime(true) - $pageStart, 4);
                \App\Logger::log("[PdfExtractor-OCR] Sayfa {$i} görsele çevrildi - Süre: {$pageElapsed} saniye");
            }
        } catch (\Exception $e) {
            \App\Logger::log("[PdfExtractor-OCR] Imagick HATA: " . $e->getMessage());
            foreach ($images as $img) {
                if (file_exists($img)) {
                    unlink($img);
                }
            }
            throw new \RuntimeException("PDF görsele dönüştürülürken hata oluştu: " . $e->getMessage());
        }

        $allText = '';

        foreach ($images as $index => $pagePath) {
            echo " ";
            if (function_exists('ob_flush') && ob_get_level() > 0) {
                @ob_flush();
            }
            flush();

            try {
                $tessStart = microtime(true);
                $ocr = new TesseractOCR($pagePath);
                // @phpstan-ignore-next-line
                $ocr->lang('tur', 'eng');
                // @phpstan-ignore-next-line
                $ocr->psm(3);
                $text = $ocr->run();

                if (file_exists($pagePath)) {
                    unlink($pagePath);
                }

                $tessElapsed = round(microtime(true) - $tessStart, 4);
                \App\Logger::log("[PdfExtractor-OCR] Sayfa {$index} Tesseract okuması bitti - Süre: {$tessElapsed} saniye");
                $allText .= $text . "\n";
            } catch (\Exception $e) {
                \App\Logger::log("[PdfExtractor-OCR] UYARI: Sayfa {$index} okunamadı - Hata: " . $e->getMessage());
                if (file_exists($pagePath)) {
                    unlink($pagePath);
                }
            }
        }

        $barcodes = $this->processText($allText);
        $elapsed = round(microtime(true) - $ocrStart, 4);
        \App\Logger::log("[PdfExtractor-OCR] Tamamlandı - Toplam Süre: {$elapsed} saniye | Benzersiz Barkod: " . count($barcodes));

        return $barcodes;
    }

    /**
     * Helper to process text: strips all whitespaces from each line,
     * and skips lines shorter than 18 characters.
     *
     * @param string $text
     * @return array<string>
     */
    private function processText(string $text): array
    {
        $rawLines = explode("\n", str_replace("\r", "", str_replace("\f", "\n", $text)));
        $processedLines = [];

        foreach ($rawLines as $line) {
            $lineStrip = trim($line);
            if ($lineStrip === '') {
                continue;
            }

            $processedLines[] = $lineStrip;
        }

        return $processedLines;
    }

    /**
     * Extracts raw text from PDF using the specified mode.
     *
     * @param string $filePath
     * @param string $mode
     * @return string
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function extractRawText(string $filePath, string $mode): string
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("PDF dosyası bulunamadı: {$filePath}");
        }

        // Try Python extraction first with --raw flag
        $pythonScript = dirname(__DIR__) . '/src/reconcile.py';
        if (file_exists($pythonScript)) {
            $cacheFile = $this->getCacheFilePath($filePath, $mode, true);
            $cached = $this->readCache($cacheFile);
            if ($cached !== null && isset($cached['raw_text']) && is_string($cached['raw_text'])) {
                \App\Logger::log("[PdfExtractor-Python-Raw] Önbellekten okundu - Mod: {$mode} | " . basename($filePath));
                return $cached['raw_text'];
            }

            $cmd = $this->buildPythonCommand($pythonScript, $mode, $filePath, true);

            $result = $this->runProcessWithTimeout($cmd, 'PdfExtractor-Python-Live-Raw');
            if ($result !== null && !$result['timed_out']) {
                $data = json_decode(trim($result['stdout']), true);
                if (is_array($data) && isset($data['success']) && $data['success'] === true) {
                    $this->writeCache($cacheFile, $data);
                    return $data['raw_text'] ?? '';
                }
            } elseif ($result !== null && $result['timed_out']) {
                \App\Logger::log("[PdfExtractor-Python-Raw] HATA: İşlem " . self::PYTHON_TIMEOUT_SECONDS . " saniye içinde tamamlanmadı, sonlandırıldı.");
            }
        }

        // Native PHP fallback if Python script is not found or fails
        if ($mode === 'ocr') {
            throw new \RuntimeException("Native PHP OCR ham çıktısı desteklenmiyor. Python motoru yüklü olmalıdır.");
        }

        // Native PDF text parsing fallback (Smalot)
        $parser = new \Smalot\PdfParser\Parser();
        try {
            $pdf = $parser->parseFile($filePath);
            return $pdf->getText();
        } catch (\Exception $e) {
            throw new \RuntimeException("PDF dosyası ayrıştırılamadı (Smalot): " . $e->getMessage());
        }
    }
}
