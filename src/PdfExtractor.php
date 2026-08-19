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

    private bool $useOcr = true;

    /** @var array<string, array<string>> In-memory cache for extracted lines */
    private array $extractCache = [];

    /** @var array<string, string> In-memory cache for raw text */
    private array $rawTextCache = [];

    private ?GoogleVisionClient $googleVisionClient = null;

    /** Maximum seconds to wait for subprocesses before terminating. */
    private const PROCESS_TIMEOUT_SECONDS = 900;

    public function __construct(?GoogleVisionClient $googleVisionClient = null)
    {
        if ($googleVisionClient !== null) {
            $this->googleVisionClient = $googleVisionClient;
        } else {
            $apiKey = Env::get('GOOGLE_VISION_API_KEY');
            if ($apiKey !== null && trim($apiKey) !== '' && $apiKey !== 'your_google_cloud_vision_api_key_here') {
                try {
                    $this->googleVisionClient = new GoogleVisionClient($apiKey);
                } catch (\Exception $e) {
                    \App\Logger::log("[PdfExtractor] GoogleVisionClient başlatılamadı: " . $e->getMessage());
                }
            }
        }
    }

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

        $mtime = filemtime($filePath);
        $mode = $this->useOcr ? 'ocr' : 'text';
        $cacheKey = md5($filePath . '_' . $mtime . '_' . $mode);

        if (isset($this->extractCache[$cacheKey])) {
            \App\Logger::log("[PdfExtractor] Önbellekten okundu (extract): " . basename($filePath) . " [Mod: {$mode}]");
            return $this->extractCache[$cacheKey];
        }

        // 1. Google Cloud Vision OCR (Primary Cloud Engine if OCR enabled & API key set)
        if ($this->useOcr && $this->googleVisionClient !== null) {
            $googleResult = $this->extractWithGoogleVision($filePath);
            if ($googleResult !== null && !empty($googleResult)) {
                $this->extractCache[$cacheKey] = $googleResult;
                return $googleResult;
            }
            \App\Logger::log("[PdfExtractor] Google Cloud Vision boş döndü veya hata oluştu, yerel motora geçiliyor...");
        }

        // 2. Python Backend (PaddleOCR / Tesseract Fallback)
        $pythonResult = $this->extractWithPython($filePath, $mode);
        if ($pythonResult !== null) {
            $this->extractCache[$cacheKey] = $pythonResult;
            return $pythonResult;
        }

        // 3. Fallback to native PHP extraction
        if ($this->useOcr) {
            $result = $this->extractOcrPhp($filePath);
            $this->extractCache[$cacheKey] = $result;
            return $result;
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

        $this->extractCache[$cacheKey] = $barcodes;
        return $barcodes;
    }

    /**
     * Extracts text from PDF using Google Cloud Vision API.
     *
     * @param string $filePath
     * @return array<string>|null
     */
    private function extractWithGoogleVision(string $filePath): ?array
    {
        if ($this->googleVisionClient === null) {
            return null;
        }

        $start = microtime(true);
        \App\Logger::log("[PdfExtractor-GoogleVision] Google Cloud Vision OCR başlıyor: " . basename($filePath));

        $tempDir = dirname(__DIR__) . '/var/tmp';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $tempPrefix = $tempDir . '/gpage_' . uniqid();
        $images = [];

        try {
            // Render PDF pages to compressed grayscale PNGs (300 DPI: kucuk/dusuk kontrastli
            // barkodlarin Vision tarafindan tamamen atlanmasini azaltmak icin 200'den yukseltildi)
            $checkPdftoppm = PHP_OS_FAMILY === 'Windows' ? 'where pdftoppm' : 'which pdftoppm';
            $hasPdftoppm = (string)shell_exec($checkPdftoppm);

            if (trim($hasPdftoppm) !== '') {
                $cmd = 'pdftoppm -png -gray -r 300 ' . escapeshellarg($filePath) . ' ' . escapeshellarg($tempPrefix);
                shell_exec($cmd);

                $pattern = $tempPrefix . '-*.png';
                $pageFiles = glob($pattern);
                if ($pageFiles !== false && !empty($pageFiles)) {
                    sort($pageFiles, SORT_NATURAL);
                    $images = $pageFiles;
                }
            }

            // Imagick fallback if pdftoppm did not produce images
            if (empty($images) && class_exists('\Imagick')) {
                $ping = new \Imagick();
                $ping->pingImage($filePath);
                $pageCount = $ping->getNumberImages();
                $ping->clear();
                $ping->destroy();

                for ($i = 0; $i < $pageCount; $i++) {
                    $img = new \Imagick();
                    $img->setResolution(300, 300);
                    $img->readImage($filePath . '[' . $i . ']');
                    $img->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
                    $img->setImageFormat('png');
                    $outPath = $tempPrefix . '-' . ($i + 1) . '.png';
                    $img->writeImage($outPath);
                    $img->clear();
                    $img->destroy();
                    $images[] = $outPath;
                }
            }

            if (empty($images)) {
                \App\Logger::log("[PdfExtractor-GoogleVision] HATA: PDF sayfaları görsele dönüştürülemedi.");
                return null;
            }

            $pageTexts = $this->googleVisionClient->annotateImages($images);
            $fullText = implode("\f", $pageTexts);

            $elapsed = round(microtime(true) - $start, 4);
            $lines = $this->processText($fullText);
            \App\Logger::log("[PdfExtractor-GoogleVision] Başarıyla tamamlandı - Toplam Sayfa: " . count($images) . " | Süre: {$elapsed} saniye | Çıkarılan Satır: " . count($lines));

            return $lines;
        } catch (\Throwable $e) {
            \App\Logger::log("[PdfExtractor-GoogleVision] HATA: " . $e->getMessage());
            return null;
        } finally {
            foreach ($images as $img) {
                if (file_exists($img)) {
                    @unlink($img);
                }
            }
        }
    }

    /**
     * Extracts store name from PDF file.
     *
     * @param string $filePath
     * @param string|null $originalName
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
     * @param string $filePath
     * @param string|null $originalName
     * @return array<string, string>
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

        $pythonExecutable = 'python3';
        if (PHP_OS_FAMILY === 'Windows') {
            $pythonExecutable = 'python';
        } else {
            $hasPython3 = (string)shell_exec('which python3 2>/dev/null');
            if (trim($hasPython3) === '') {
                $pythonExecutable = 'python';
            }
        }

        $cmd = $pythonExecutable . ' ' . escapeshellarg($pythonScript) . ' --mode ' . escapeshellarg($mode) . ' --pdf ' . escapeshellarg($filePath);

        $result = $this->runProcessWithTimeout($cmd, 'PdfExtractor-Python-Live');
        if ($result === null) {
            \App\Logger::log("[PdfExtractor-Python] HATA: Süreç başlatılamadı.");
            return null;
        }

        if ($result['timed_out']) {
            \App\Logger::log("[PdfExtractor-Python] HATA: İşlem " . self::PROCESS_TIMEOUT_SECONDS . " saniye içinde tamamlanmadı, sonlandırıldı.");
            return null;
        }

        /** @var array{success?: bool, lines?: array<string>, elapsed_time?: float}|null $data */
        $data = json_decode(trim($result['stdout']), true);
        if (is_array($data) && isset($data['success']) && $data['success'] === true) {
            $this->barcodeToOriginalMap = [];
            $this->mismatches = [];

            $timeLog = isset($data['elapsed_time']) ? "Süre: {$data['elapsed_time']} sn" : "";
            \App\Logger::log("[PdfExtractor-Python] Başarıyla tamamlandı - Mod: {$mode} | {$timeLog}");

            return $data['lines'] ?? [];
        }

        \App\Logger::log("[PdfExtractor-Python] Hata veya uyumsuz çıktı: " . trim(substr($result['stdout'], 0, 200)));
        return null;
    }

    /**
     * Runs a shell command via proc_open.
     *
     * @param string $cmd
     * @param string $liveLogPrefix
     * @return array{stdout: string, stderr: string, timed_out: bool}|null
     */
    private function runProcessWithTimeout(string $cmd, string $liveLogPrefix): ?array
    {
        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
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
                while (($chunk = fread($pipes[1], 8192)) !== '' && $chunk !== false) {
                    $stdoutData .= $chunk;
                }
                while (($chunk = fread($pipes[2], 8192)) !== '' && $chunk !== false) {
                    $stderrData .= $chunk;
                }
                break;
            }

            if ((microtime(true) - $startTime) > self::PROCESS_TIMEOUT_SECONDS) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }

            if ($numChanged === 0 && !$hasRead) {
                usleep(10000);
            }
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return ['stdout' => $stdoutData, 'stderr' => $stderrData, 'timed_out' => $timedOut];
    }

    /**
     * Native PHP OCR fallback using Imagick + Tesseract.
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
            @mkdir($tempDir, 0775, true);
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
                $pageImagick = new \Imagick();
                $pageImagick->setResolution(300, 300);
                $pageImagick->readImage($filePath . '[' . $i . ']');
                $pageImagick->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
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
                    @unlink($img);
                }
            }
            throw new \RuntimeException("PDF görsele dönüştürülürken hata oluştu: " . $e->getMessage());
        }

        $allText = '';

        foreach ($images as $index => $pagePath) {
            try {
                $tessStart = microtime(true);
                $ocr = new TesseractOCR($pagePath);
                // @phpstan-ignore-next-line
                $ocr->lang('tur', 'eng');
                // @phpstan-ignore-next-line
                $ocr->psm(3);
                $text = $ocr->run();

                if (file_exists($pagePath)) {
                    @unlink($pagePath);
                }

                $tessElapsed = round(microtime(true) - $tessStart, 4);
                \App\Logger::log("[PdfExtractor-OCR] Sayfa {$index} Tesseract okuması bitti - Süre: {$tessElapsed} saniye");
                $allText .= $text . "\n";
            } catch (\Exception $e) {
                \App\Logger::log("[PdfExtractor-OCR] UYARI: Sayfa {$index} okunamadı - Hata: " . $e->getMessage());
                if (file_exists($pagePath)) {
                    @unlink($pagePath);
                }
            }
        }

        $barcodes = $this->processText($allText);
        $elapsed = round(microtime(true) - $ocrStart, 4);
        \App\Logger::log("[PdfExtractor-OCR] Tamamlandı - Toplam Süre: {$elapsed} saniye | Benzersiz Barkod: " . count($barcodes));

        return $barcodes;
    }

    /**
     * Process text lines and skip empty lines.
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
     * Extracts raw text from PDF.
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

        $mtime = filemtime($filePath);
        $cacheKey = md5($filePath . '_' . $mtime . '_' . $mode);
        if (isset($this->rawTextCache[$cacheKey])) {
            \App\Logger::log("[PdfExtractor] Önbellekten okundu (extractRawText): " . basename($filePath) . " [Mod: {$mode}]");
            return $this->rawTextCache[$cacheKey];
        }

        if ($mode === 'text') {
            $parser = new Parser();
            try {
                $pdf = $parser->parseFile($filePath);
                $text = $pdf->getText();
                $this->rawTextCache[$cacheKey] = $text;
                return $text;
            } catch (\Exception $e) {
                // Fallback to pdftotext
                $output = shell_exec('pdftotext -layout ' . escapeshellarg($filePath) . ' -');
                if ($output !== null && $output !== false) {
                    $this->rawTextCache[$cacheKey] = (string)$output;
                    return (string)$output;
                }
                throw new \RuntimeException("PDF dosyası ayrıştırılamadı: " . $e->getMessage());
            }
        }

        $lines = $this->extract($filePath);
        $rawText = implode("\n", $lines);
        $this->rawTextCache[$cacheKey] = $rawText;
        return $rawText;
    }
}
