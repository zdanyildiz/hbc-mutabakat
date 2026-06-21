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
     * @param string $filePath
     * @return string
     */
    public function extractStoreName(string $filePath): string
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
                ]);
                return $cleanedStore;
            }

            if (mb_stripos($line, 'Mağaza') !== false) {
                $foundStoreLabel = true;
            }
        }

        $filename = basename($filePath, '.pdf');
        if (preg_match('/^T\d+$/i', $filename)) {
            return strtoupper($filename);
        }

        return 'Bilinmeyen Mağaza';
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

        $pythonExecutable = 'python3';
        // Check python3 availability
        $checkCommand = PHP_OS_FAMILY === 'Windows' ? 'where python3' : 'which python3';
        $hasPython3 = (string)shell_exec($checkCommand);
        if (trim($hasPython3) === '') {
            $pythonExecutable = 'python';
        }

        $cmd = $pythonExecutable . ' ' . escapeshellarg($pythonScript) . ' --mode ' . escapeshellarg($mode) . ' --pdf ' . escapeshellarg($filePath);

        $descriptorspec = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"]  // stderr
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes);

        if (!is_resource($process)) {
            \App\Logger::log("[PdfExtractor-Python] HATA: Süreç başlatılamadı.");
            return null;
        }

        // Set non-blocking mode for stdout and stderr
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdoutData = '';
        $stderrData = '';

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
                                        \App\Logger::log("[PdfExtractor-Python-Live]" . $cleanLog);
                                    } else {
                                        \App\Logger::log("[PdfExtractor-Python-Stderr] " . $line);
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

            if ($numChanged === 0 && !$hasRead) {
                usleep(10000); // 10ms CPU sleep
            }
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $data = json_decode(trim($stdoutData), true);
        if (is_array($data) && isset($data['success']) && $data['success'] === true) {
            $this->barcodeToOriginalMap = [];
            $this->mismatches = [];
            
            $timeLog = isset($data['elapsed_time']) ? "Süre: {$data['elapsed_time']} sn" : "";
            \App\Logger::log("[PdfExtractor-Python] Başarıyla tamamlandı - Mod: {$mode} | {$timeLog}");
            
            return $data['lines'] ?? [];
        }

        \App\Logger::log("[PdfExtractor-Python] Hata veya uyumsuz çıktı: " . trim(substr($stdoutData, 0, 200)));
        return null;
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
            mkdir($tempDir, 0777, true);
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
                $pageImagick->setResolution(150, 150); // Optimized resolution
                $pageImagick->readImage($filePath . '[' . $i . ']');

                $pageImagick->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
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
                $ocr->lang('eng');
                // @phpstan-ignore-next-line
                $ocr->psm(6);
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

            // Boşlukları temizle (tüm satırda)
            $cleanLine = (string)preg_replace('/\s+/u', '', $lineStrip);

            // 18 karakterden küçükse es geç
            if (mb_strlen($cleanLine) < 18) {
                continue;
            }

            $processedLines[] = $cleanLine;
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
            $pythonExecutable = 'python3';
            $checkCommand = PHP_OS_FAMILY === 'Windows' ? 'where python3' : 'which python3';
            $hasPython3 = (string)shell_exec($checkCommand);
            if (trim($hasPython3) === '') {
                $pythonExecutable = 'python';
            }

            $cmd = $pythonExecutable . ' ' . escapeshellarg($pythonScript) . ' --raw --mode ' . escapeshellarg($mode) . ' --pdf ' . escapeshellarg($filePath);
            
            $descriptorspec = [
                0 => ["pipe", "r"], // stdin
                1 => ["pipe", "w"], // stdout
                2 => ["pipe", "w"]  // stderr
            ];

            $process = proc_open($cmd, $descriptorspec, $pipes);
            if (is_resource($process)) {
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);

                $stdoutData = '';
                $stderrData = '';

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
                                                \App\Logger::log("[PdfExtractor-Python-Live-Raw]" . $cleanLog);
                                            } else {
                                                \App\Logger::log("[PdfExtractor-Python-Stderr-Raw] " . $line);
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

                    if ($numChanged === 0 && !$hasRead) {
                        usleep(10000);
                    }
                }

                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                $data = json_decode(trim($stdoutData), true);
                if (is_array($data) && isset($data['success']) && $data['success'] === true) {
                    return $data['raw_text'] ?? '';
                }
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
