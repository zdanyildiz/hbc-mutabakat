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

    private bool $useOcr = false;

    // Character mapping table to fix OCR and font encoding issues
    private const OCR_MAP = [
        'l' => '1', 'ı' => '1', 'I' => '1', 'i' => '1', '!' => '1', '[' => '1', ']' => '3',
        'B' => '8', 'M' => '0', 'O' => '0', 'o' => '0', 'E' => '8', 'S' => '5', 's' => '5', 'ü' => '4',
        't' => '1', '|' => '1', '}' => '1', '{' => '8', 'j' => '3', 'J' => '3'
    ];

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

        $commands = [
            'python3 ' . escapeshellarg($pythonScript) . ' --mode ' . escapeshellarg($mode) . ' --pdf ' . escapeshellarg($filePath),
            'python ' . escapeshellarg($pythonScript) . ' --mode ' . escapeshellarg($mode) . ' --pdf ' . escapeshellarg($filePath)
        ];

        foreach ($commands as $cmd) {
            $output = (string)shell_exec($cmd . ' 2>&1');
            if (trim($output) === '') {
                continue;
            }

            $data = json_decode($output, true);
            if (is_array($data) && isset($data['success']) && $data['success'] === true) {
                $this->barcodeToOriginalMap = $data['barcode_to_original'] ?? [];
                $this->mismatches = $data['mismatches'] ?? [];
                
                $timeLog = isset($data['elapsed_time']) ? "Süre: {$data['elapsed_time']} sn" : "";
                \App\Logger::log("[PdfExtractor-Python] Başarıyla tamamlandı - Mod: {$mode} | {$timeLog}");
                
                return $data['barcodes'] ?? [];
            } else {
                \App\Logger::log("[PdfExtractor-Python] Hata veya uyumsuz çıktı: " . trim(substr($output, 0, 200)));
            }
        }

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
     * Helper to process text, apply character mapping and extract barcodes.
     *
     * @param string $text
     * @return array<string>
     */
    private function processText(string $text): array
    {
        $barcodes = [];
        $this->mismatches = []; // Disabled mismatches completely as per user request

        // Process line by line on original text
        $lines = explode("\n", $text);
        $preprocessedLines = [];

        // 1. Preprocess split lines (joining wrapping lines)
        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }

            $convLine = strtr($line, self::OCR_MAP);
            $digitsInLine = (string)preg_replace('/\D/', '', $convLine);
            $digitCount = strlen($digitsInLine);

            if ($digitCount >= 11 && $digitCount <= 15) {
                if (isset($lines[$i + 1])) {
                    $nextLine = trim($lines[$i + 1]);
                    $convNext = strtr($nextLine, self::OCR_MAP);
                    $nextDigits = (string)preg_replace('/\D/', '', $convNext);
                    if ($nextDigits !== '' && strlen($nextLine) <= 5) {
                        $line .= " " . $nextLine;
                        $i++;
                    }
                }
            }
            $preprocessedLines[] = $line;
        }

        // 2. Extract barcodes line by line
        foreach ($preprocessedLines as $line) {
            $columns = preg_split('/(?:\s{2,}|\t)/u', $line);
            if ($columns === false) {
                $columns = [$line];
            }

            /** @var array<array{original: string, word: string, converted: string, letters_count: int}> $candidates */
            $candidates = [];

            foreach ($columns as $col) {
                $colClean = trim($col);
                if ($colClean === '') {
                    continue;
                }

                // Remove internal spaces or hyphens in a single column (OCR splitting fix)
                $word = (string)preg_replace('/[\s-]+/u', '', $colClean);

                // Apply OCR MAP to see if it becomes a valid barcode
                $conv = strtr($word, self::OCR_MAP);
                $clean = (string)preg_replace('/\D/', '', $conv);

                // Smart sequence number trimming:
                $length = strlen($clean);
                if ($length > 18) {
                    foreach ([18, 17, 16] as $targetLen) {
                        $suffix = substr($clean, -$targetLen);
                        if (preg_match('/^(?:1|6|7)/', $suffix)) {
                            $clean = $suffix;
                            break;
                        }
                    }
                }

                $finalLen = strlen($clean);
                if ($finalLen >= 16 && $finalLen <= 20) {
                    // Count letters in original word to check for pure digits
                    preg_match_all('/[a-zA-Z]/u', $word, $letterMatches);
                    $lettersCount = count($letterMatches[0]);
                    
                    $candidates[] = [
                        'original' => $colClean,
                        'word' => $word,
                        'converted' => $clean,
                        'letters_count' => $lettersCount
                    ];
                }
            }

            if (empty($candidates)) {
                continue;
            }

            // Group similar candidates (Levenshtein distance <= 2)
            /** @var array<array{original: string, word: string, converted: string, letters_count: int}> $resolved */
            $resolved = [];
            foreach ($candidates as $cand) {
                $isMerged = false;
                foreach ($resolved as $key => $res) {
                    if (levenshtein($cand['converted'], $res['converted']) <= 2) {
                        // Compare and keep the one with fewer letters (more digits)
                        if ($cand['letters_count'] < $res['letters_count']) {
                            $resolved[$key] = $cand;
                        }
                        $isMerged = true;
                        break;
                    }
                }
                if (!$isMerged) {
                    $resolved[] = $cand;
                }
            }

            foreach ($resolved as $res) {
                $barcodes[] = $res['converted'];
                $this->barcodeToOriginalMap[$res['converted']] = $res['original'];
            }
        }

        return array_values(array_unique($barcodes));
    }
}
