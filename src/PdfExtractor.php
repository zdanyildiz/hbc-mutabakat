<?php

declare(strict_types=1);

namespace App;

use Smalot\PdfParser\Parser;

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

    /**
     * @var array<string, array<array{barcode: ?string, barcode_fallback: ?string, store: ?string, irsaliye_no?: ?string, sevk_id?: ?string, kargo_firma?: ?string}>>
     * In-memory cache for manifest table rows from Google Document AI.
     */
    private array $manifestRowsCache = [];

    /** @var array<string, array<string, string>> */
    private array $docAiFormFieldsCache = [];

    private ?GoogleDocumentAiClient $googleDocumentAiClient = null;

    public function __construct(?GoogleDocumentAiClient $googleDocumentAiClient = null)
    {
        if ($googleDocumentAiClient !== null) {
            $this->googleDocumentAiClient = $googleDocumentAiClient;
        } else {
            $docAiProjectId = Env::get('GOOGLE_DOCAI_PROJECT_ID');
            $docAiProcessorId = Env::get('GOOGLE_DOCAI_PROCESSOR_ID');
            $docAiLocation = (string)(Env::get('GOOGLE_DOCAI_LOCATION') ?? 'eu');
            $docAiApiKey = Env::get('GOOGLE_DOCAI_API_KEY') ?? Env::get('GOOGLE_VISION_API_KEY');
            $docAiCreds = Env::get('GOOGLE_APPLICATION_CREDENTIALS');

            if ($docAiProjectId !== null && trim($docAiProjectId) !== '' && $docAiProcessorId !== null && trim($docAiProcessorId) !== '') {
                try {
                    $this->googleDocumentAiClient = new GoogleDocumentAiClient(
                        $docAiProjectId,
                        $docAiLocation,
                        $docAiProcessorId,
                        $docAiApiKey,
                        $docAiCreds
                    );
                    \App\Logger::log("[PdfExtractor] Google Document AI Client başarıyla başlatıldı (Processor: {$docAiProcessorId})");
                } catch (\Throwable $e) {
                    \App\Logger::log("[PdfExtractor] Google Document AI başlatılamadı: " . $e->getMessage());
                }
            }
        }
    }

    public function setGoogleDocumentAiClient(?GoogleDocumentAiClient $client): void
    {
        $this->googleDocumentAiClient = $client;
    }

    public function getGoogleDocumentAiClient(): ?GoogleDocumentAiClient
    {
        return $this->googleDocumentAiClient;
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
     * Returns the manifest table rows (barcode / barcode_fallback / store / irsaliye / sevk / kargo)
     * for a PDF, if it was extracted via Google Document AI.
     *
     * @return array<array{barcode: ?string, barcode_fallback: ?string, store: ?string, irsaliye_no?: ?string, sevk_id?: ?string, kargo_firma?: ?string}>
     */
    public function getManifestRows(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $cacheKey = md5($filePath . '_' . filemtime($filePath));
        return $this->manifestRowsCache[$cacheKey] ?? [];
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

        // OCR Modunda YALNIZCA Google Cloud Document AI çalışır (Fallback motorlar devre dışı)
        if ($this->useOcr) {
            if ($this->googleDocumentAiClient === null) {
                throw new \RuntimeException(
                    "Google Cloud Document AI yapılandırılmamış veya başlatılamadı. " .
                    "Lütfen .env dosyasında GOOGLE_DOCAI_PROJECT_ID ve GOOGLE_DOCAI_PROCESSOR_ID tanımlayın."
                );
            }

            $docAiResult = $this->extractWithDocumentAi($filePath);
            $this->extractCache[$cacheKey] = $docAiResult;
            return $docAiResult;
        }

        // Salt Metin Modu (OCR kapalıyken dijital PDF okuma)
        $pdfStart = microtime(true);
        \App\Logger::log("[PdfExtractor-Text] PDF okuma başladı: " . basename($filePath));

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
            \App\Logger::log("[PdfExtractor-Text] UYARI: pdftotext bulunamadı, Smalot PDF Parser devreye giriyor.");
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
        \App\Logger::log("[PdfExtractor-Text] Tamamlandı - Süre: {$elapsed} saniye | Benzersiz Satır: " . count($barcodes));

        $this->extractCache[$cacheKey] = $barcodes;
        return $barcodes;
    }

    /**
     * Extracts structured text and tables from PDF using Google Cloud Document AI.
     *
     * @param string $filePath
     * @return array<string>
     * @throws \RuntimeException
     */
    private function extractWithDocumentAi(string $filePath): array
    {
        if ($this->googleDocumentAiClient === null) {
            throw new \RuntimeException("Google Cloud Document AI istemcisi başlatılmamış.");
        }

        $start = microtime(true);
        \App\Logger::log("[PdfExtractor-DocumentAI] Google Cloud Document AI ayrıştırması başlıyor: " . basename($filePath));

        try {
            $docResult = $this->googleDocumentAiClient->processDocument($filePath, 'application/pdf');

            $cacheKey = md5($filePath . '_' . filemtime($filePath));
            $this->manifestRowsCache[$cacheKey] = $docResult['rows'];
            $this->docAiFormFieldsCache[$cacheKey] = $docResult['form_fields'];

            $lines = $docResult['lines'];

            // Tablodan çıkarılan yapısal satırları da metin satırları olarak ekle (tam kapsama için)
            foreach ($docResult['rows'] as $row) {
                $rowParts = [];
                if ($row['barcode'] !== null) {
                    $rowParts[] = $row['barcode'];
                }
                if ($row['barcode_fallback'] !== null && $row['barcode_fallback'] !== $row['barcode']) {
                    $rowParts[] = $row['barcode_fallback'];
                }
                if ($row['irsaliye_no'] !== null) {
                    $rowParts[] = $row['irsaliye_no'];
                }
                if ($row['store'] !== null) {
                    $rowParts[] = $row['store'];
                }
                if (!empty($rowParts)) {
                    $lines[] = implode(' ', $rowParts);
                }
            }

            $processedLines = $this->processText(implode("\n", $lines));
            $elapsed = round(microtime(true) - $start, 4);

            \App\Logger::log(sprintf(
                "[PdfExtractor-DocumentAI] Başarıyla tamamlandı - Sayfa: %d | Yapısal Satır: %d | Toplam Satır: %d | Süre: %s sn",
                $docResult['page_count'],
                count($docResult['rows']),
                count($processedLines),
                (string)$elapsed
            ));

            return $processedLines;
        } catch (\Throwable $e) {
            \App\Logger::log("[PdfExtractor-DocumentAI] HATA: " . $e->getMessage());
            throw new \RuntimeException("Google Cloud Document AI İşlem Hatası: " . $e->getMessage(), (int)$e->getCode(), $e);
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

        $cacheKey = md5($filePath . '_' . filemtime($filePath));
        if (isset($this->docAiFormFieldsCache[$cacheKey])) {
            $fields = $this->docAiFormFieldsCache[$cacheKey];
            foreach ($fields as $key => $val) {
                $k = mb_strtolower($key, 'UTF-8');
                if (str_contains($k, 'mağaza') || str_contains($k, 'magaza')) {
                    $cleanVal = trim($val);
                    if ($cleanVal !== '') {
                        return $cleanVal;
                    }
                }
            }
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
