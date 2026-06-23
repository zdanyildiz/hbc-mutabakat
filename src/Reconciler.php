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
     * @return ReconciliationResult
     */
    public function reconcile(string $excelPath, string|array $pdfPaths): ReconciliationResult
    {
        $terminalBarcodes = $this->excelExtractor->extract($excelPath);

        $pdfPaths = (array)$pdfPaths;
        $pdfLines = [];
        foreach ($pdfPaths as $pdfPath) {
            $extracted = $this->pdfExtractor->extract($pdfPath);
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
                if ($pdfLineClean !== null && (str_contains($pdfLineClean, $terminalBarcode) || str_contains($terminalBarcode, $pdfLineClean))) {
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

            foreach ($words as $word) {
                // Kelimeden sadece rakamları alalım
                $digits = preg_replace('/\D/', '', $word);
                
                // Eğer bu barkod zaten başarıyla eşleştiyse fazla koli olamaz
                if ($digits !== null && in_array($digits, $matched, true)) {
                    continue;
                }

                // Eğer kelime 12-20 hane arasında geçerli bir barkodsa ekleyelim
                if ($digits !== null && strlen($digits) >= 12 && strlen($digits) <= 20) {
                    $filteredExtraInStore[] = $digits;
                }
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
                if ($cleanExtra === null || $cleanExtra === '') {
                    $cleanExtra = $extraBarcode;
                }
                
                // Eğer temizlenen sayı uzunluğu yakınsa ve levenshtein mesafesi <= 2 ise
                $len = strlen($cleanExtra);
                if ($len >= 12 && $len <= 22) {
                    $distance = levenshtein($missingBarcode, $cleanExtra);
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
            $matchedText
        );
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
