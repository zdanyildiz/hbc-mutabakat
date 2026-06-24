<?php

declare(strict_types=1);

namespace App;

class Reconciler
{


    public function __construct(
        private readonly ExcelExtractor $excelExtractor,
        private readonly PdfExtractor $pdfExtractor
    ) {}

    /**
     * Reconciles barcodes between an Excel/CSV file and one or more PDF files.
     *
     * Uses a three-tier matching strategy:
     * 1. Exact match (with leading-zero normalization)
     * 2. Fuzzy match via Levenshtein distance (≤ threshold → suspected match)
     * 3. No match → missing/extra
     *
     * @param string $excelPath
     * @param string|array<string> $pdfPaths
     * @param string|int $companyId
     * @param bool $filterByStore Mağaza rota manifestolarında geçen BAŞKA mağazalara ait
     *     satırları (örn. T410.pdf içinde T503, T405 gibi farklı mağaza kodlu satırlar)
     *     "fazla koli" taramasına dahil etmemek için satırları kendi mağaza koduna göre filtreler.
     * @param array<string, string> $pdfOriginalFilenames Geçici dosya yolu => orijinal dosya adı eşlemesi.
     *     Mağaza kodu tespiti için kullanılır (örn. "T410.pdf"). Verilmezse $pdfPaths'in kendisi kullanılır.
     * @return ReconciliationResult
     */
    public function reconcile(
        string $excelPath,
        string|array $pdfPaths,
        string|int $companyId = '1',
        bool $filterByStore = true,
        array $pdfOriginalFilenames = []
    ): ReconciliationResult {
        $allTerminalBarcodes = $this->excelExtractor->extract($excelPath);

        $lengthRule = CompanyRules::getLengthRule($companyId);

        $terminalBarcodes = [];
        $invalidBarcodes = [];

        foreach ($allTerminalBarcodes as $barcode) {
            // Hane (uzunluk) kuralının yanı sıra firmaya özel ön ek kuralı da uygulanır.
            // LCW için: 18 haneli VE "15" ya da "16" ile başlamalı.
            if (CompanyRules::isValid($barcode, $companyId)) {
                $terminalBarcodes[] = $barcode;
            } else {
                $invalidBarcodes[] = $barcode;
            }
        }

        $pdfPaths = (array)$pdfPaths;
        $pdfLines = [];
        foreach ($pdfPaths as $pdfPath) {
            $extracted = $this->pdfExtractor->extract($pdfPath);

            if ($filterByStore) {
                $ownStoreCode = $this->inferOwnStoreCode($pdfPath, $pdfOriginalFilenames[$pdfPath] ?? null);
                if ($ownStoreCode !== null) {
                    $extracted = $this->filterLinesByOwnStore($extracted, $ownStoreCode);
                }
            }

            $pdfLines = array_merge($pdfLines, $extracted);
        }

        $pdfLinesPool = array_values($pdfLines);

        $matchedOcr = [];
        $matchedText = [];
        $missingInStore = [];

        // 1. Aşama: Tam Eşleşenler (OCR Taraması ile)
        foreach ($terminalBarcodes as $terminalBarcode) {
            $found = false;
            foreach ($pdfLinesPool as $idx => $pdfLine) {
                $pdfLineClean = preg_replace('/\s+/', '', $pdfLine);
                if ($pdfLineClean === null || $pdfLineClean === '') {
                    continue;
                }

                // İleri yön: barkod, PDF satırının içinde geçiyor (normal eşleşme).
                $contains = str_contains($pdfLineClean, $terminalBarcode);

                // Geri yön: PDF satırının tamamı barkodun bir parçası (bölünmüş/kısmi okuma).
                // SADECE rakamdan oluşan ve >= 6 haneli parçalarla sınırlı; aksi halde
                // "474" gibi sayfa/sıra numaraları barkodun içinde geçtiği için yanlışlıkla
                // eşleşip gerçek bir eksik/fazla koliyi gizleyebiliyordu.
                $isPartial = strlen($pdfLineClean) >= 6
                    && ctype_digit($pdfLineClean)
                    && str_contains($terminalBarcode, $pdfLineClean);

                if ($contains || $isPartial) {
                    $matchedOcr[] = $terminalBarcode;
                    unset($pdfLinesPool[$idx]);
                    $found = true;
                    break;
                }
            }
            
            // Eğer tam olarak bulunamadıysa, satırlar arasında bölünmüş olabileceğini kontrol et (OCR)
            if (!$found) {
                if ($this->removeSplitBarcode($terminalBarcode, $pdfLinesPool)) {
                    $matchedOcr[] = $terminalBarcode;
                    $found = true;
                }
            }

            if (!$found) {
                $missingInStore[] = $terminalBarcode;
            }
        }

        // 2. Aşama: OCR sonrasında eksik kalanlar için metin tabanlı otomatik tarama
        if (!empty($missingInStore)) {
            \App\Logger::log("[Reconciler-TextSearch] OCR sonrası eksik kalan " . count($missingInStore) . " barkod için metin tabanlı arama başlıyor...");
            
            // PDF'lerin ham metinlerini alalım ve satırlara bölelim
            $rawPdfLines = [];
            foreach ($pdfPaths as $pdfPath) {
                try {
                    $rawText = $this->pdfExtractor->extractRawText($pdfPath, 'text');
                    $lines = explode("\n", str_replace("\r", "", str_replace("\f", "\n", $rawText)));
                    foreach ($lines as $line) {
                        $trimmed = trim($line);
                        if ($trimmed !== '') {
                            $rawPdfLines[] = $trimmed;
                        }
                    }
                } catch (\Exception $e) {
                    \App\Logger::log("[Reconciler-TextSearch] HATA: Ham metin çıkarılamadı (" . basename($pdfPath) . "): " . $e->getMessage());
                }
            }

            // Karakter replace haritası (Bozuk Karakter -> Rakam)
            $charReplaceMap = [
                'l' => '1', '|' => '1', 'I' => '1', 'ı' => '1', 't' => '1', '!' => '1', 'i' => '1',
                ']' => '3', 'j' => '3', 'J' => '3',
                'E' => '8', 'o' => '0', 'O' => '0', 'B' => '8', 'S' => '5', 's' => '5', 'ü' => '4', 
                '{' => '8', '}' => '1', '[' => '1'
            ];

            $stillMissing = [];
            foreach ($missingInStore as $missingBarcode) {
                $foundInText = false;
                
                foreach ($rawPdfLines as $idx => $rawLine) {
                    // Boşlukları temizle
                    $cleanLine = preg_replace('/\s+/u', '', $rawLine);
                    if ($cleanLine === null) {
                        $cleanLine = $rawLine;
                    }
                    
                    // Bozuk karakterleri replace et
                    $replacedLine = strtr($cleanLine, $charReplaceMap);
                    
                    // Rakam dışındaki karakterleri temizle
                    $digitsOnlyLine = preg_replace('/\D/', '', $replacedLine);
                    if ($digitsOnlyLine === null) {
                        $digitsOnlyLine = $replacedLine;
                    }

                    // Barkod numarasını satırda ara
                    if (str_contains($digitsOnlyLine, $missingBarcode)) {
                        $matchedText[] = $missingBarcode;
                        // Eşleşme olunca satırı havuzdan sil ve bir sonraki eksik barkoda geç
                        unset($rawPdfLines[$idx]);
                        $foundInText = true;
                        \App\Logger::log("[Reconciler-TextSearch] Eksik Barkod Metin Aramasıyla Eşleşti: " . $missingBarcode);
                        break;
                    }
                }

                // Eğer metin içinde tam olarak bulunamadıysa, bölünmüş aramayı dene (Metin Fallback)
                if (!$foundInText) {
                    if ($this->removeSplitBarcodeText($missingBarcode, $rawPdfLines, $charReplaceMap)) {
                        $matchedText[] = $missingBarcode;
                        $foundInText = true;
                        \App\Logger::log("[Reconciler-TextSearch] Eksik Barkod Metin Aramasında Bölünmüş Olarak Eşleşti: " . $missingBarcode);
                    }
                }

                if (!$foundInText) {
                    $stillMissing[] = $missingBarcode;
                }
            }
            $missingInStore = $stillMissing;
        }

        // 3. Aşama: Hala eksik kalan barkodlar arasında, PDF satırındaki OCR gürültüsü
        // (harf/sembol) nedeniyle 1. ve 2. aşamadaki ham metin karşılaştırmalarını
        // atlamış olabilecek TAM eşleşmeleri tespit et. Bu adım, "fazla koli" taramasından
        // önce çalışmalıdır; aksi halde aynı barkod hem eksik hem fazla listesinde görünür.
        if (!empty($missingInStore)) {
            $rawPdfLinesForExact = [];
            foreach ($pdfPaths as $pdfPath) {
                try {
                    $rawText = $this->pdfExtractor->extractRawText($pdfPath, 'text');
                    $lines = explode("\n", str_replace("\r", "", str_replace("\f", "\n", $rawText)));
                    foreach ($lines as $line) {
                        $trimmed = trim($line);
                        if ($trimmed !== '') {
                            $rawPdfLinesForExact[] = $trimmed;
                        }
                    }
                } catch (\Exception $e) {
                    // Ham metin alınamazsa bu aşama atlanır, sonraki aşamalar yine de çalışır.
                }
            }

            $stillMissingAfterExact = [];
            foreach ($missingInStore as $missingBarcode) {
                $exactFound = false;
                foreach ($rawPdfLinesForExact as $rawLine) {
                    $digitsOnly = preg_replace('/\D/', '', $rawLine);
                    if ($digitsOnly !== null && $digitsOnly === $missingBarcode) {
                        $matchedText[] = $missingBarcode;
                        $exactFound = true;
                        \App\Logger::log("[Reconciler-ExactNoise] OCR Gürültüsü Temizlenince Tam Eşleşti: " . $missingBarcode);
                        break;
                    }
                }
                if (!$exactFound) {
                    $stillMissingAfterExact[] = $missingBarcode;
                }
            }
            $missingInStore = $stillMissingAfterExact;
        }

        $matched = array_merge($matchedOcr, $matchedText);

        // Fazla kolileri filtrele (Kalan satırlardaki gerçek barkodları ayıklıyoruz)
        $filteredExtraInStore = [];
        foreach ($pdfLinesPool as $extraLine) {
            // PDF başlık satırlarını ve tabloların kenar başlıklarını filtreliyoruz
            if (preg_match('/(rapor|magaza|mağaza|mutabakat|belge|tarih|kodu|sira|sıra|teslim|koli|onay|mudur|müdür|sayfa|kargo|firma|depo|irs|sevk|toplam|adedi|alacak)/iu', $extraLine)) {
                continue;
            }

            // Satırı boşluklara göre kelimelere bölüp her bir kelimeyi ayrı ayrı analiz edelim
            $words = preg_split('/\s+/', $extraLine);
            if ($words === false) {
                $words = [$extraLine];
            }

            // Bir satırda birden fazla barkod hanesi geçebilir (örn. "Barkod" ve
            // "Müşteri Barkod" sütunları aynı satırda yan yana basılı, ya da aynı
            // barkod satırda 2 kez yazılı). Bir paket = bir satır olduğu için, bu
            // satırdan zaten eşleşmiş bir barkod çıkıyorsa satırın tamamı yok sayılır;
            // aksi halde satırdan en fazla TEK bir barkod adayı alınır.
            $lineMatchedAlready = false;
            $lineCandidate = null;

            foreach ($words as $word) {
                // Kelimeden sadece rakamları alalım
                $digits = preg_replace('/\D/', '', $word);

                if (!is_string($digits) || $digits === '') {
                    continue;
                }

                // Eğer bu satırdaki barkodlardan biri zaten başarıyla eşleştiyse,
                // satırdaki diğer (yanlış okunmuş veya tekrar yazılmış) barkod
                // adayı tamamen yok sayılır.
                if (in_array($digits, $matched, true)) {
                    $lineMatchedAlready = true;
                    break;
                }

                // Eğer kelime geçerli hane kuralları arasında geçerli bir barkodsa,
                // satırın ilk geçerli adayı olarak not edelim (satırdan en fazla 1 tane alınır).
                if ($lineCandidate === null && strlen($digits) >= $lengthRule['min'] && strlen($digits) <= $lengthRule['max']) {
                    $lineCandidate = $digits;
                }
            }

            if (!$lineMatchedAlready && $lineCandidate !== null) {
                $filteredExtraInStore[] = $lineCandidate;
            }
        }

        $extraInStore = array_values(array_unique($filteredExtraInStore));
        $suspectedMatches = [];
        $stillMissingAfterFuzzy = [];

        foreach ($missingInStore as $missingBarcode) {
            $fuzzyFound = false;
            foreach ($extraInStore as $idx => $extraBarcode) {
                // PDF'teki fazla satırı sadece sayılar kalacak şekilde temizle
                $cleanExtra = preg_replace('/\D/', '', $extraBarcode);
                if (!is_string($cleanExtra) || $cleanExtra === '') {
                    $cleanExtra = $extraBarcode;
                }
                
                // Eğer temizlenen sayı uzunluğu yakınsa ve levenshtein mesafesi <= 2 ise
                $len = strlen($cleanExtra);
                if ($len >= $lengthRule['min'] && $len <= $lengthRule['max'] + 2) {
                    $distance = levenshtein($missingBarcode, $cleanExtra);
                    if ($distance === 0) {
                        // Tam eşleşme: "şüpheli" değil, kesin eşleşmedir. Aynı barkodun
                        // hem eksik hem fazla listesinde aynı anda görünmesini engeller.
                        $matchedText[] = $missingBarcode;
                        $matched[] = $missingBarcode;
                        unset($extraInStore[$idx]);
                        $fuzzyFound = true;
                        \App\Logger::log("[Reconciler-Fuzzy] Tam Eşleşme (Mesafe 0) Bulundu: " . $missingBarcode);
                        break;
                    }
                    if ($distance > 0 && $distance <= 2) {
                        $suspectedMatches[] = [
                            'terminal_barcode' => $missingBarcode,
                            'store_barcode' => $cleanExtra,
                            'distance' => $distance
                        ];
                        unset($extraInStore[$idx]);
                        $fuzzyFound = true;
                        \App\Logger::log("[Reconciler-Fuzzy] Şüpheli Eşleşme Bulundu: " . $missingBarcode . " <-> " . $cleanExtra . " (Mesafe: " . $distance . ")");
                        break;
                    }
                }
            }
            if (!$fuzzyFound) {
                $stillMissingAfterFuzzy[] = $missingBarcode;
            }
        }
        $missingInStore = $stillMissingAfterFuzzy;
        $extraInStore = array_values($extraInStore);

        return new ReconciliationResult(
            $terminalBarcodes,
            $pdfLines,
            $matched,
            array_values($missingInStore),
            array_values($extraInStore),
            $suspectedMatches,
            $matchedOcr,
            $matchedText,
            $invalidBarcodes
        );
    }

    /**
     * Infers the store code (e.g. "T410") that a PDF's own manifest section belongs to,
     * so that other stores' rows printed in the same route manifest can be filtered out.
     *
     * @param string $pdfPath
     * @param string|null $originalFilename Original (non-temp) filename, if known.
     * @return string|null Uppercased store code, or null if it could not be determined.
     */
    private function inferOwnStoreCode(string $pdfPath, ?string $originalFilename): ?string
    {
        $candidateName = $originalFilename ?? $pdfPath;
        $filename = basename($candidateName, '.pdf');
        if (preg_match('/^T\d{3,4}$/i', $filename)) {
            return strtoupper($filename);
        }

        try {
            $storeName = $this->pdfExtractor->extractStoreName($pdfPath);
        } catch (\Exception $e) {
            return null;
        }

        if (preg_match('/\bT\d{3,4}\b/i', $storeName, $m)) {
            return strtoupper($m[0]);
        }

        return null;
    }

    /**
     * Drops PDF lines that reference a different store's code (e.g. "T503") than the
     * PDF's own store, while keeping lines that mention the own code or no store code at all
     * (continuation/split-barcode lines without a trailing store column).
     *
     * @param array<string> $lines
     * @param string $ownStoreCode
     * @return array<string>
     */
    private function filterLinesByOwnStore(array $lines, string $ownStoreCode): array
    {
        $filtered = [];
        foreach ($lines as $line) {
            preg_match_all('/\bT\d{3,4}\b/i', $line, $matches);
            $codesInLine = array_map('strtoupper', $matches[0]);

            if (empty($codesInLine) || in_array($ownStoreCode, $codesInLine, true)) {
                $filtered[] = $line;
            }
        }

        return $filtered;
    }

    /**
     * Checks if a barcode is split across two adjacent lines and removes them from the pool.
     *
     * @param string $barcode
     * @param array<string> &$lines Reference to the lines pool
     * @return bool True if found and matched (and removed from pool), false otherwise.
     */
    private function removeSplitBarcode(string $barcode, array &$lines): bool
    {
        $len = strlen($barcode);
        if ($len < 10) {
            return false;
        }

        $minPrefixLen = max(10, $len - 4);
        $maxPrefixLen = $len - 1;

        $keys = array_keys($lines);
        $keysCount = count($keys);

        for ($prefixLen = $minPrefixLen; $prefixLen <= $maxPrefixLen; $prefixLen++) {
            $prefix = substr($barcode, 0, $prefixLen);
            $suffix = substr($barcode, $prefixLen);

            for ($i = 0; $i < $keysCount; $i++) {
                $currentKey = $keys[$i];
                if (!isset($lines[$currentKey])) {
                    continue;
                }

                $currentLineDigits = preg_replace('/\D/', '', $lines[$currentKey]);
                if ($currentLineDigits === null || !str_contains($currentLineDigits, $prefix)) {
                    continue;
                }

                // Prefix bulundu. Şimdi sonraki 1 veya 2 geçerli satırda suffix'i arayalım
                for ($k = 1; $k <= 2; $k++) {
                    if ($i + $k >= $keysCount) {
                        continue;
                    }

                    $nextKey = $keys[$i + $k];
                    if (!isset($lines[$nextKey])) {
                        continue;
                    }

                    $nextLineDigits = preg_replace('/\D/', '', $lines[$nextKey]);
                    if ($nextLineDigits === null) {
                        continue;
                    }

                    if (str_contains($nextLineDigits, $suffix)) {
                        // Hem prefix satırını hem de suffix satırını havuzdan kaldır
                        unset($lines[$currentKey]);
                        unset($lines[$nextKey]);
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Checks if a barcode is split across two adjacent text lines and removes them from the pool.
     *
     * @param string $barcode
     * @param array<string> &$lines Reference to the text lines pool
     * @param array<string, string> $charReplaceMap
     * @return bool True if found and matched, false otherwise.
     */
    private function removeSplitBarcodeText(string $barcode, array &$lines, array $charReplaceMap): bool
    {
        $len = strlen($barcode);
        if ($len < 10) {
            return false;
        }

        $minPrefixLen = max(10, $len - 4);
        $maxPrefixLen = $len - 1;

        $keys = array_keys($lines);
        $keysCount = count($keys);

        for ($prefixLen = $minPrefixLen; $prefixLen <= $maxPrefixLen; $prefixLen++) {
            $prefix = substr($barcode, 0, $prefixLen);
            $suffix = substr($barcode, $prefixLen);

            for ($i = 0; $i < $keysCount; $i++) {
                $currentKey = $keys[$i];
                if (!isset($lines[$currentKey])) {
                    continue;
                }

                // Satırı temizle ve normalize et
                $cleanLine = preg_replace('/\s+/u', '', $lines[$currentKey]);
                if ($cleanLine === null) {
                    $cleanLine = $lines[$currentKey];
                }
                $replacedLine = strtr($cleanLine, $charReplaceMap);
                $currentLineDigits = preg_replace('/\D/', '', $replacedLine);

                if ($currentLineDigits === null || !str_contains($currentLineDigits, $prefix)) {
                    continue;
                }

                // Prefix eşleşti! Sonraki satırlarda suffix'i arayalım
                for ($k = 1; $k <= 2; $k++) {
                    if ($i + $k >= $keysCount) {
                        continue;
                    }

                    $nextKey = $keys[$i + $k];
                    if (!isset($lines[$nextKey])) {
                        continue;
                    }

                    $nextClean = preg_replace('/\s+/u', '', $lines[$nextKey]);
                    if ($nextClean === null) {
                        $nextClean = $lines[$nextKey];
                    }
                    $nextReplaced = strtr($nextClean, $charReplaceMap);
                    $nextLineDigits = preg_replace('/\D/', '', $nextReplaced);

                    if ($nextLineDigits === null) {
                        continue;
                    }

                    if (str_contains($nextLineDigits, $suffix)) {
                        unset($lines[$currentKey]);
                        unset($lines[$nextKey]);
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
