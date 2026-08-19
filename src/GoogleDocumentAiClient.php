<?php

declare(strict_types=1);

namespace App;

/**
 * Google Cloud Document AI Client
 *
 * Google Document AI v1 REST API üzerinden PDF ve görsel dokümanları işler.
 * Özellikle "Form Parser" veya "Custom Document Extractor" işlemcileri ile
 * tablolardaki hücreleri (headerRows, bodyRows, cells) ve form alanlarını (formFields)
 * deterministik ve yapısal olarak ayıklar.
 *
 * Kimlik Doğrulama Desteği:
 * 1. Service Account JSON (Google Application Credentials) -> Kurumsal OAuth2 JWT Bearer Token
 * 2. API Key -> REST endpoint ?key= parametresi
 */
class GoogleDocumentAiClient
{
    private string $projectId;
    private string $location;
    private string $processorId;
    private ?string $apiKey = null;
    private ?string $credentialsPath = null;
    /** @var array<string, mixed>|null */
    private ?array $credentialsJson = null;

    /** @var array{token: string, expires_at: int}|null Token önbelleği */
    private ?array $tokenCache = null;

    /**
     * @param string $projectId Google Cloud Project ID
     * @param string $location Bölge (örn: 'eu', 'us')
     * @param string $processorId Document AI Processor ID
     * @param string|null $apiKey Google Cloud API Key
     * @param string|null $credentialsPath Service Account JSON dosya yolu
     */
    public function __construct(
        string $projectId,
        string $location,
        string $processorId,
        ?string $apiKey = null,
        ?string $credentialsPath = null
    ) {
        $this->projectId = trim($projectId);
        $this->location = trim($location);
        $this->processorId = trim($processorId);
        $this->apiKey = $apiKey !== null && trim($apiKey) !== '' ? trim($apiKey) : null;
        $this->credentialsPath = $credentialsPath !== null && trim($credentialsPath) !== '' ? trim($credentialsPath) : null;

        if ($this->projectId === '' || $this->location === '' || $this->processorId === '') {
            throw new \InvalidArgumentException("Document AI yapılandırması eksik (Project ID, Location veya Processor ID boş olamaz).");
        }

        if ($this->credentialsPath !== null && file_exists($this->credentialsPath)) {
            $content = file_get_contents($this->credentialsPath);
            if ($content !== false) {
                /** @var array<string, mixed>|null $decoded */
                $decoded = json_decode($content, true);
                if (is_array($decoded) && isset($decoded['private_key'], $decoded['client_email'])) {
                    $this->credentialsJson = $decoded;
                }
            }
        }
    }

    /**
     * PDF veya Görsel dosyasını Document AI ile işler.
     * Sayfa sayısı 15'ten fazla olan büyük PDF'lerde otomatik olarak sayfaları
     * paralel Document AI isteklerine bölerek limit hatalarını önler.
     *
     * @param string $filePath İşlenecek dosyanın mutlak yolu
     * @param string $mimeType Dosya MIME tipi ('application/pdf', 'image/png', 'image/jpeg')
     * @return array{
     *     text: string,
     *     lines: array<string>,
     *     tables: array<array<array<string>>>,
     *     rows: array<array{barcode: ?string, barcode_fallback: ?string, store: ?string, irsaliye_no: ?string, sevk_id: ?string, kargo_firma: ?string}>,
     *     form_fields: array<string, string>,
     *     page_count: int
     * }
     * @throws \RuntimeException
     */
    public function processDocument(string $filePath, string $mimeType = 'application/pdf'): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Document AI: Dosya bulunamadı: {$filePath}");
        }

        // Eğer dosya PDF ise sayfa sayısını kontrol et (Document AI online process 15 sayfa limiti)
        if ($mimeType === 'application/pdf' || str_ends_with(strtolower($filePath), '.pdf')) {
            $pageCount = $this->getPdfPageCount($filePath);
            if ($pageCount > 15) {
                \App\Logger::log("[DocumentAiClient] PDF {$pageCount} sayfa (>15 limit). Sayfalar paralel işleniyor...");
                return $this->processPdfPagesParallel($filePath);
            }
        }

        return $this->executeSingleProcess($filePath, $mimeType);
    }

    /**
     * Tek bir dosya (PDF <= 15 sayfa veya görsel) için Document AI process çağrısı yapar.
     *
     * @param string $filePath
     * @param string $mimeType
     * @return array{
     *     text: string,
     *     lines: array<string>,
     *     tables: array<array<array<string>>>,
     *     rows: array<array{barcode: ?string, barcode_fallback: ?string, store: ?string, irsaliye_no: ?string, sevk_id: ?string, kargo_firma: ?string}>,
     *     form_fields: array<string, string>,
     *     page_count: int
     * }
     */
    public function executeSingleProcess(string $filePath, string $mimeType): array
    {
        $fileData = file_get_contents($filePath);
        if ($fileData === false) {
            throw new \RuntimeException("Document AI: Dosya okunamadı: {$filePath}");
        }

        $base64Data = base64_encode($fileData);

        $endpoint = sprintf(
            'https://%s-documentai.googleapis.com/v1/projects/%s/locations/%s/processors/%s:process',
            $this->location,
            $this->projectId,
            $this->location,
            $this->processorId
        );

        if ($this->apiKey !== null && $this->credentialsJson === null) {
            $endpoint .= '?key=' . urlencode($this->apiKey);
        }

        $payload = json_encode([
            'rawDocument' => [
                'content' => $base64Data,
                'mimeType' => $mimeType,
            ],
            'skipHumanReview' => true,
        ]);

        if ($payload === false) {
            throw new \RuntimeException("Document AI: Payload JSON oluşturulamadı.");
        }

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ];

        // Service Account OAuth2 Token ekleme
        $accessToken = $this->getAccessToken();
        if ($accessToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $accessToken;
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new \RuntimeException("Document AI: cURL başlatılamadı.");
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new \RuntimeException("Document AI cURL Hatası: " . $curlError);
        }

        if ($httpCode !== 200) {
            $errSnippet = is_string($response) ? substr($response, 0, 500) : '';
            throw new \RuntimeException("Document AI HTTP Hata ({$httpCode}): " . $errSnippet);
        }

        /** @var array<string, mixed>|null $responseData */
        $responseData = json_decode((string)$response, true);
        if (!is_array($responseData) || !isset($responseData['document'])) {
            throw new \RuntimeException("Document AI: Geçersiz API yanıtı.");
        }

        /** @var array<string, mixed> $doc */
        $doc = $responseData['document'];

        return $this->parseDocumentResponse($doc);
    }

    /**
     * 15 sayfadan uzun PDF'leri sayfa görsellerine bölüp paralel curl multi ile Document AI'a gönderir.
     *
     * @param string $pdfPath
     * @return array{
     *     text: string,
     *     lines: array<string>,
     *     tables: array<array<array<string>>>,
     *     rows: array<array{barcode: ?string, barcode_fallback: ?string, store: ?string, irsaliye_no: ?string, sevk_id: ?string, kargo_firma: ?string}>,
     *     form_fields: array<string, string>,
     *     page_count: int
     * }
     */
    private function processPdfPagesParallel(string $pdfPath): array
    {
        $tempDir = dirname(__DIR__) . '/var/tmp';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $tempPrefix = $tempDir . '/docai_' . uniqid();
        $images = [];

        try {
            $checkPdftoppm = PHP_OS_FAMILY === 'Windows' ? 'where pdftoppm' : 'which pdftoppm';
            $hasPdftoppm = (string)shell_exec($checkPdftoppm);

            if (trim($hasPdftoppm) !== '') {
                $cmd = 'pdftoppm -png -r 200 ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tempPrefix);
                shell_exec($cmd);

                $pattern = $tempPrefix . '-*.png';
                $pageFiles = glob($pattern);
                if ($pageFiles !== false && !empty($pageFiles)) {
                    sort($pageFiles, SORT_NATURAL);
                    $images = $pageFiles;
                }
            }

            if (empty($images) && class_exists('\Imagick')) {
                $ping = new \Imagick();
                $ping->pingImage($pdfPath);
                $pageCount = $ping->getNumberImages();
                $ping->clear();
                $ping->destroy();

                for ($i = 0; $i < $pageCount; $i++) {
                    $img = new \Imagick();
                    $img->setResolution(200, 200);
                    $img->readImage($pdfPath . '[' . $i . ']');
                    $img->setImageFormat('png');
                    $outPath = $tempPrefix . '-' . ($i + 1) . '.png';
                    $img->writeImage($outPath);
                    $img->clear();
                    $img->destroy();
                    $images[] = $outPath;
                }
            }

            if (empty($images)) {
                // Fallback: Doğrudan tek parça dene
                return $this->executeSingleProcess($pdfPath, 'application/pdf');
            }

            $endpoint = sprintf(
                'https://%s-documentai.googleapis.com/v1/projects/%s/locations/%s/processors/%s:process',
                $this->location,
                $this->projectId,
                $this->location,
                $this->processorId
            );

            if ($this->apiKey !== null && $this->credentialsJson === null) {
                $endpoint .= '?key=' . urlencode($this->apiKey);
            }

            $accessToken = $this->getAccessToken();
            $headers = [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
            ];
            if ($accessToken !== null) {
                $headers[] = 'Authorization: Bearer ' . $accessToken;
            }

            $allTexts = [];
            $allLines = [];
            $allTables = [];
            $allRows = [];
            $allFormFields = [];

            // 8'li batch'lerle paralel cURL
            $chunks = array_chunk($images, 8, true);

            foreach ($chunks as $chunk) {
                $mh = curl_multi_init();
                $curlHandles = [];

                foreach ($chunk as $idx => $imgPath) {
                    $imgData = file_get_contents($imgPath);
                    if ($imgData === false) {
                        continue;
                    }

                    $payload = json_encode([
                        'rawDocument' => [
                            'content' => base64_encode($imgData),
                            'mimeType' => 'image/png',
                        ],
                        'skipHumanReview' => true,
                    ]);

                    $ch = curl_init($endpoint);
                    if ($ch === false) {
                        continue;
                    }

                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$payload);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

                    curl_multi_add_handle($mh, $ch);
                    $curlHandles[$idx] = $ch;
                }

                $running = null;
                do {
                    $status = curl_multi_exec($mh, $running);
                    if ($running > 0) {
                        curl_multi_select($mh, 0.1);
                    }
                } while ($running > 0 && $status === CURLM_OK);

                foreach ($curlHandles as $idx => $ch) {
                    $response = curl_multi_getcontent($ch);
                    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);

                    if ($httpCode === 200 && is_string($response)) {
                        /** @var array<string, mixed>|null $data */
                        $data = json_decode($response, true);
                        if (is_array($data) && isset($data['document'])) {
                            /** @var array<string, mixed> $doc */
                            $doc = $data['document'];
                            $parsed = $this->parseDocumentResponse($doc);
                            $allTexts[$idx] = $parsed['text'];
                            $allLines = array_merge($allLines, $parsed['lines']);
                            $allTables = array_merge($allTables, $parsed['tables']);
                            $allRows = array_merge($allRows, $parsed['rows']);
                            $allFormFields = array_merge($allFormFields, $parsed['form_fields']);
                        }
                    }
                }

                curl_multi_close($mh);
            }

            ksort($allTexts);
            $fullTextCombined = implode("\n\f\n", $allTexts);

            return [
                'text' => $fullTextCombined,
                'lines' => $allLines,
                'tables' => $allTables,
                'rows' => $allRows,
                'form_fields' => $allFormFields,
                'page_count' => count($images),
            ];
        } finally {
            foreach ($images as $img) {
                if (file_exists($img)) {
                    @unlink($img);
                }
            }
        }
    }

    /**
     * PDF sayfa sayısını tespit eder.
     */
    private function getPdfPageCount(string $pdfPath): int
    {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($pdfPath);
            return count($pdf->getPages());
        } catch (\Throwable) {
            return 1;
        }
    }

    /**
     * Document AI JSON yanıtını parse ederek yapısal tablo, satır ve form verilerini üretir.
     *
     * @param array<string, mixed> $doc
     * @return array{
     *     text: string,
     *     lines: array<string>,
     *     tables: array<array<array<string>>>,
     *     rows: array<array{barcode: ?string, barcode_fallback: ?string, store: ?string, irsaliye_no: ?string, sevk_id: ?string, kargo_firma: ?string}>,
     *     form_fields: array<string, string>,
     *     page_count: int
     * }
     */
    public function parseDocumentResponse(array $doc): array
    {
        $fullText = isset($doc['text']) && is_string($doc['text']) ? $doc['text'] : '';
        /** @var array<int, array<string, mixed>> $pages */
        $pages = isset($doc['pages']) && is_array($doc['pages']) ? $doc['pages'] : [];

        $allTables = [];
        $extractedRows = [];
        $formFields = [];

        foreach ($pages as $page) {
            // 1. Form Alanları (Key-Value Çiftleri)
            /** @var array<int, array<string, mixed>> $fields */
            $fields = isset($page['formFields']) && is_array($page['formFields']) ? $page['formFields'] : [];
            foreach ($fields as $field) {
                /** @var array<string, mixed>|null $fieldNameObj */
                $fieldNameObj = isset($field['fieldName']) && is_array($field['fieldName']) ? $field['fieldName'] : null;
                /** @var array<string, mixed>|null $fieldValueObj */
                $fieldValueObj = isset($field['fieldValue']) && is_array($field['fieldValue']) ? $field['fieldValue'] : null;

                /** @var array<string, mixed>|null $fieldNameAnchor */
                $fieldNameAnchor = $fieldNameObj !== null && isset($fieldNameObj['textAnchor']) && is_array($fieldNameObj['textAnchor']) ? $fieldNameObj['textAnchor'] : null;
                /** @var array<string, mixed>|null $fieldValueAnchor */
                $fieldValueAnchor = $fieldValueObj !== null && isset($fieldValueObj['textAnchor']) && is_array($fieldValueObj['textAnchor']) ? $fieldValueObj['textAnchor'] : null;

                $fieldName = $this->extractTextFromAnchor($fullText, $fieldNameAnchor);
                $fieldValue = $this->extractTextFromAnchor($fullText, $fieldValueAnchor);
                $cleanName = trim(preg_replace('/\s+/', ' ', $fieldName) ?? '');
                $cleanVal = trim(preg_replace('/\s+/', ' ', $fieldValue) ?? '');
                if ($cleanName !== '' && $cleanVal !== '') {
                    $formFields[$cleanName] = $cleanVal;
                }
            }

            // 2. Tablolar
            /** @var array<int, array<string, mixed>> $tables */
            $tables = isset($page['tables']) && is_array($page['tables']) ? $page['tables'] : [];
            foreach ($tables as $table) {
                $tableMatrix = [];
                $headerMatrix = [];

                /** @var array<int, array<string, mixed>> $headerRows */
                $headerRows = isset($table['headerRows']) && is_array($table['headerRows']) ? $table['headerRows'] : [];
                foreach ($headerRows as $hRow) {
                    $rowCells = [];
                    /** @var array<int, array<string, mixed>> $cells */
                    $cells = isset($hRow['cells']) && is_array($hRow['cells']) ? $hRow['cells'] : [];
                    foreach ($cells as $cell) {
                        /** @var array<string, mixed>|null $layout */
                        $layout = isset($cell['layout']) && is_array($cell['layout']) ? $cell['layout'] : null;
                        /** @var array<string, mixed>|null $anchor */
                        $anchor = $layout !== null && isset($layout['textAnchor']) && is_array($layout['textAnchor']) ? $layout['textAnchor'] : null;
                        $cellText = $this->extractTextFromAnchor($fullText, $anchor);
                        $rowCells[] = trim($cellText);
                    }
                    $headerMatrix[] = $rowCells;
                }

                $headers = !empty($headerMatrix) ? $headerMatrix[0] : [];

                /** @var array<int, array<string, mixed>> $bodyRows */
                $bodyRows = isset($table['bodyRows']) && is_array($table['bodyRows']) ? $table['bodyRows'] : [];
                foreach ($bodyRows as $bRow) {
                    $rowCells = [];
                    /** @var array<int, array<string, mixed>> $cells */
                    $cells = isset($bRow['cells']) && is_array($bRow['cells']) ? $bRow['cells'] : [];
                    foreach ($cells as $cell) {
                        /** @var array<string, mixed>|null $layout */
                        $layout = isset($cell['layout']) && is_array($cell['layout']) ? $cell['layout'] : null;
                        /** @var array<string, mixed>|null $anchor */
                        $anchor = $layout !== null && isset($layout['textAnchor']) && is_array($layout['textAnchor']) ? $layout['textAnchor'] : null;
                        $cellText = $this->extractTextFromAnchor($fullText, $anchor);
                        // Hücre içi çift satır kırılmalarını tek boşlukla birleştir (örn: "LLI..." ve "7")
                        $cleanCell = trim(preg_replace('/\s+/u', ' ', $cellText) ?? '');
                        $rowCells[] = $cleanCell;
                    }
                    $tableMatrix[] = $rowCells;

                    // Yapısal Mutabakat Satırı Çıkarımı
                    $rowRecord = $this->mapCellsToManifestRow($rowCells, $headers);
                    if ($rowRecord !== null) {
                        $extractedRows[] = $rowRecord;
                    }
                }

                $allTables[] = $tableMatrix;
            }
        }

        // Düz metin satırları (Geriye dönük uyumluluk ve Regex/Deterministik eşleştirme için)
        $lines = [];
        $rawLines = explode("\n", str_replace("\r", "", $fullText));
        foreach ($rawLines as $l) {
            $trimmed = trim($l);
            if ($trimmed !== '') {
                $lines[] = $trimmed;
            }
        }

        return [
            'text' => $fullText,
            'lines' => $lines,
            'tables' => $allTables,
            'rows' => $extractedRows,
            'form_fields' => $formFields,
            'page_count' => count($pages),
        ];
    }

    /**
     * TextAnchor nesnesindeki textSegments aralıklarını kullanarak metni çıkarır.
     *
     * @param string $fullText
     * @param array<string, mixed>|null $textAnchor
     * @return string
     */
    private function extractTextFromAnchor(string $fullText, ?array $textAnchor): string
    {
        if ($textAnchor === null || !isset($textAnchor['textSegments']) || !is_array($textAnchor['textSegments'])) {
            return '';
        }

        $result = '';
        /** @var array<string, string|int> $segment */
        foreach ($textAnchor['textSegments'] as $segment) {
            $start = isset($segment['startIndex']) ? (int)$segment['startIndex'] : 0;
            $end = isset($segment['endIndex']) ? (int)$segment['endIndex'] : $start;

            if ($end > $start) {
                // UTF-8 güvenli kesme
                $chunk = mb_substr($fullText, $start, $end - $start, 'UTF-8');
                $result .= $chunk;
            }
        }

        return $result;
    }

    /**
     * Tablodaki bir satırın hücrelerini başlık ve içerik kurallarına göre analiz ederek mutabakat satırına dönüştürür.
     *
     * @param array<int, string> $cells
     * @param array<int, string> $headers
     * @return array{barcode: ?string, barcode_fallback: ?string, store: ?string, irsaliye_no: ?string, sevk_id: ?string, kargo_firma: ?string}|null
     */
    private function mapCellsToManifestRow(array $cells, array $headers): ?array
    {
        if (empty($cells)) {
            return null;
        }

        $barcode = null;
        $barcodeFallback = null;
        $store = null;
        $irsaliyeNo = null;
        $sevkId = null;
        $kargoFirma = null;

        $barcodesFound = [];

        // 1. Kolon Başlıklarına Göre Eşleme
        foreach ($cells as $idx => $cellValue) {
            $header = isset($headers[$idx]) ? mb_strtolower($headers[$idx], 'UTF-8') : '';
            $digitsOnly = preg_replace('/\D/', '', $cellValue) ?? '';
            $digitsLen = strlen($digitsOnly);

            // Başlık "Tema Takip No" / "Barkod"
            if (str_contains($header, 'tema') || (str_contains($header, 'takip') && !str_contains($header, 'kargo')) || str_contains($header, 'barkod')) {
                if ($digitsLen >= 14 && $digitsLen <= 20) {
                    $barcode = $digitsOnly;
                }
            } elseif (str_contains($header, 'kargotakip') || str_contains($header, 'atf')) {
                if ($digitsLen >= 14 && $digitsLen <= 20) {
                    $barcodeFallback = $digitsOnly;
                }
            } elseif (str_contains($header, 'irs') || str_contains($header, 'seri')) {
                $irsaliyeNo = $cellValue;
            } elseif (str_contains($header, 'sevk')) {
                $sevkId = $cellValue;
            } elseif (str_contains($header, 'depo') || str_contains($header, 'mağaza') || str_contains($header, 'magaza')) {
                if (preg_match('/[A-ZÇĞİÖŞÜ0-9]{3,7}/u', $cellValue, $m)) {
                    $store = $m[0];
                }
            } elseif (str_contains($header, 'firma') || str_contains($header, 'kargo')) {
                $kargoFirma = $cellValue;
            }

            if ($digitsLen >= 14 && $digitsLen <= 20) {
                $barcodesFound[] = $digitsOnly;
            }
        }

        // 2. Başlık bulunamadıysa içerik paternlerine göre tespit
        if ($barcode === null && !empty($barcodesFound)) {
            $barcode = $barcodesFound[0];
            if (count($barcodesFound) > 1) {
                $barcodeFallback = $barcodesFound[1];
            }
        }

        if ($barcode === null && $barcodeFallback === null) {
            return null; // Barkod içermeyen satırları (özet/toplam satırı gibi) atla
        }

        // Mağaza/depo kodu içeriğe göre yedek arama
        if ($store === null) {
            foreach ($cells as $cellValue) {
                if (preg_match('/\b([A-ZÇĞİÖŞÜ]{1,6}\d{0,4})\b/u', $cellValue, $m)) {
                    $val = $m[1];
                    $len = strlen($val);
                    if ($len >= 3 && $len <= 7 && !ctype_digit($val)) {
                        $store = $val;
                        break;
                    }
                }
            }
        }

        return [
            'barcode' => $barcode ?? $barcodeFallback,
            'barcode_fallback' => $barcodeFallback ?? $barcode,
            'store' => $store,
            'irsaliye_no' => $irsaliyeNo,
            'sevk_id' => $sevkId,
            'kargo_firma' => $kargoFirma,
        ];
    }

    /**
     * Service Account JSON kullanarak Google OAuth2 Access Token alır ve önbelleğe alır.
     */
    private function getAccessToken(): ?string
    {
        if ($this->credentialsJson === null) {
            return null;
        }

        $now = time();
        if ($this->tokenCache !== null && $this->tokenCache['expires_at'] > ($now + 60)) {
            return $this->tokenCache['token'];
        }

        $clientEmail = isset($this->credentialsJson['client_email']) && is_string($this->credentialsJson['client_email']) ? $this->credentialsJson['client_email'] : '';
        $privateKey = isset($this->credentialsJson['private_key']) && is_string($this->credentialsJson['private_key']) ? $this->credentialsJson['private_key'] : '';
        $tokenUri = isset($this->credentialsJson['token_uri']) && is_string($this->credentialsJson['token_uri']) ? $this->credentialsJson['token_uri'] : 'https://oauth2.googleapis.com/token';

        if ($clientEmail === '' || $privateKey === '') {
            return null;
        }

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claim = [
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/cloud-platform',
            'aud' => $tokenUri,
            'exp' => $now + 3600,
            'iat' => $now,
        ];

        $base64Header = $this->base64UrlEncode((string)json_encode($header));
        $base64Claim = $this->base64UrlEncode((string)json_encode($claim));
        $signatureInput = $base64Header . '.' . $base64Claim;

        $binarySignature = '';
        $res = openssl_sign($signatureInput, $binarySignature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$res) {
            \App\Logger::log("[DocumentAiClient] Service Account RSA imzalaması başarısız oldu.");
            return null;
        }

        $jwt = $signatureInput . '.' . $this->base64UrlEncode($binarySignature);

        // Token isteği
        $ch = curl_init($tokenUri);
        if ($ch === false) {
            return null;
        }

        $postFields = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $tokenResponse = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !is_string($tokenResponse)) {
            \App\Logger::log("[DocumentAiClient] OAuth2 token alma başarısız HTTP {$httpCode}: " . substr((string)$tokenResponse, 0, 200));
            return null;
        }

        /** @var array<string, mixed>|null $tokenData */
        $tokenData = json_decode($tokenResponse, true);
        if (is_array($tokenData) && isset($tokenData['access_token']) && is_string($tokenData['access_token'])) {
            $token = $tokenData['access_token'];
            $expiresIn = isset($tokenData['expires_in']) && is_numeric($tokenData['expires_in']) ? (int)$tokenData['expires_in'] : 3600;
            $this->tokenCache = [
                'token' => $token,
                'expires_at' => $now + $expiresIn,
            ];
            return $token;
        }

        return null;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
