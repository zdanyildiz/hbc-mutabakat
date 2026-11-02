<?php

declare(strict_types=1);

namespace App;

class Reconciler
{
    /** Bilinen glif (rakam) karışmasının ağırlıklı maliyeti. */
    private const SOFT_COST = 0.3;

    /** Şüpheli eşleşme için izin verilen toplam ağırlıklı hata bütçesi (gerçek hatalar ağırlıklı). */
    private const SUSPECT_COST_BUDGET = 2.0;

    /** Tüm farkların glif karışması olduğu durumda kabul edilen en fazla karışma sayısı. */
    private const MAX_SOFT_GLYPH = 4;

    /**
     * Görsel olarak benzer, OCR'ın sık karıştırdığı rakam çiftleri. Çift yönlü
     * değerlendirilir (8↔6 hem 8→6 hem 6→8). Digit-only OCR çıktısında geriye kalan
     * tipik hataları "muhtemel okuma hatası" (ucuz) olarak işaretlemek için kullanılır.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const GLYPH_CONFUSIONS = [
        ['8', '6'], ['8', '0'], ['8', '3'], ['8', '9'], ['8', '5'],
        ['6', '0'], ['6', '5'],
        ['0', '9'],
        ['1', '7'], ['1', '2'],
        ['5', '9'],
        ['3', '9'],
        ['9', '4'],
        ['2', '7'],
    ];

    public function __construct(
        private readonly ExcelExtractor $excelExtractor,
        private readonly PdfExtractor $pdfExtractor
    ) {}

    /**
     * Reconciles barcodes between an Excel/CSV file and one or more PDF files.
     *
     * Uses a multi-tier matching strategy:
     * 1. Exact match (OCR + split-line recovery)
     * 2. Text-layer search fallback (broken font CMap normalized)
     * 3. Glyph-weighted anchored fuzzy match (1:1) → matched / suspected
     * 4. No match → missing/extra
     *
     * @param string $excelPath
     * @param string|array<string> $pdfPaths
     * @param string|int $companyId
     * @return ReconciliationResult
     */
    public function reconcile(
        string $excelPath,
        string|array $pdfPaths,
        string|int $companyId = '1'
    ): ReconciliationResult {
        $allTerminalBarcodes = $this->excelExtractor->extract($excelPath);

        $lengthRule = CompanyRules::getLengthRule($companyId);

        $terminalBarcodes = [];
        $invalidBarcodes = [];

        foreach ($allTerminalBarcodes as $barcode) {
            // Terminal barkod geçerliliği SADECE uzunluk kuralıyla belirlenir (ön ek değil).
            // Böylece "9..." ile başlayan sarf malzeme kodları geçerli sayılır ve PDF'te
            // karşılığı olmadığı için doğru şekilde "Eksik" listesinde görünür.
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

                // Eğer kelime geçerli hane kuralları arasında VE firmanın geçerli ön ekiyle
                // başlayan bir barkodsa, satırın ilk geçerli adayı olarak not edelim (satırdan
                // en fazla 1 tane alınır). Ön ek filtresi (LCW: 15/16) OCR gürültüsünden gelen,
                // gerçek LCW barkodu olmayan 18 haneli dizilerin "fazla koli" sayılmasını engeller.
                if ($lineCandidate === null
                    && strlen($digits) >= $lengthRule['min']
                    && strlen($digits) <= $lengthRule['max']
                    && CompanyRules::hasValidPrefix($digits, $companyId)) {
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

        // Glif-ağırlıklı kenetli (1:1) eşleştirme.
        //
        // Digit-only OCR sonrası geriye kalan hatalar harf→rakam değil, görsel olarak
        // benzeyen RAKAM→RAKAM karışmalarıdır (8→6, 1→7, 5→6 ...). Düz Levenshtein bunu
        // "gerçek farklı barkod"tan ayıramaz: 3 belirsiz hane düz mesafede 3 eder ve
        // reddedilir. Bunun yerine her hane farkını bilinen karışma tablosuna göre "ucuz"
        // (glif/soft) veya "gerçek" (hard) diye sınıflandırıp eşiği gerçek hata bütçesi
        // üzerinden koyarız. Terminal listesi kapalı küme olduğundan ve her PDF adayını
        // yalnızca tek bir barkoda kenetlediğimizden (1:1), bu yanlış pozitif üretmeden
        // recall'ı artırır; aynı barkodun hem eksik hem fazla görünmesini de engeller.
        foreach ($missingInStore as $missingBarcode) {
            $bestIdx = null;
            $bestClass = '';            // 'match' (kesin) | 'suspect' (şüpheli)
            $bestHard = PHP_INT_MAX;
            $bestCost = INF;
            $bestDiff = 0;
            $bestStore = '';

            foreach ($extraInStore as $idx => $extraBarcode) {
                $cleanExtra = preg_replace('/\D/', '', $extraBarcode);
                if (!is_string($cleanExtra) || $cleanExtra === '') {
                    $cleanExtra = $extraBarcode;
                }

                $len = strlen($cleanExtra);
                if ($len < $lengthRule['min'] || $len > $lengthRule['max'] + 2) {
                    continue;
                }

                $cmp = $this->glyphCompare($missingBarcode, $cleanExtra);
                if ($cmp === null) {
                    // Uzunluklar farklı (düşmüş/eklenmiş hane): düz Levenshtein'a düşeriz;
                    // glif kredisi verilmez, tüm fark "gerçek hata" sayılır.
                    $dist = levenshtein($missingBarcode, $cleanExtra);
                    $cmp = ['diff' => $dist, 'hard' => $dist, 'soft' => 0, 'cost' => (float)$dist];
                }

                $class = '';
                if ($cmp['diff'] === 0) {
                    // Birebir (önceki aşamalar OCR gürültüsü yüzünden atlamış olabilir).
                    $class = 'match';
                } elseif ($cmp['hard'] === 0 && $cmp['soft'] <= self::MAX_SOFT_GLYPH) {
                    // Tüm farklar bilinen glif karışması → güçlü şüpheli eşleşme (asıl kazanç).
                    $class = 'suspect';
                } elseif ($cmp['hard'] >= 1 && $cmp['cost'] <= self::SUSPECT_COST_BUDGET) {
                    // Az sayıda gerçek hata + olası glif → şüpheli eşleşme.
                    $class = 'suspect';
                } else {
                    continue; // eşik dışı
                }

                // En iyi adayı seç: önce en az gerçek hata, sonra en düşük ağırlıklı maliyet.
                if ($cmp['hard'] < $bestHard
                    || ($cmp['hard'] === $bestHard && $cmp['cost'] < $bestCost)) {
                    $bestIdx = $idx;
                    $bestClass = $class;
                    $bestHard = $cmp['hard'];
                    $bestCost = $cmp['cost'];
                    $bestDiff = $cmp['diff'];
                    $bestStore = $cleanExtra;
                }
            }

            if ($bestIdx === null) {
                $stillMissingAfterFuzzy[] = $missingBarcode;
                continue;
            }

            // 1:1 kenetleme: seçilen aday havuzdan düşer.
            unset($extraInStore[$bestIdx]);

            if ($bestClass === 'match') {
                $matchedText[] = $missingBarcode;
                $matched[] = $missingBarcode;
                \App\Logger::log("[Reconciler-Fuzzy] Tam Eşleşme (Mesafe 0) Bulundu: " . $missingBarcode);
            } else {
                $suspectedMatches[] = [
                    'terminal_barcode' => $missingBarcode,
                    'store_barcode' => $bestStore,
                    'distance' => $bestDiff,
                ];
                \App\Logger::log("[Reconciler-Fuzzy] Şüpheli Eşleşme Bulundu: " . $missingBarcode . " <-> " . $bestStore . " (Hane farkı: " . $bestDiff . " | Gerçek hata: " . $bestHard . ")");
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

    /**
     * İki eşit uzunluktaki rakam dizisini hane hane karşılaştırır ve farkları,
     * bilinen glif karışma tablosuna göre "ucuz" (soft) veya "gerçek" (hard) olarak
     * sınıflandırır. Uzunluklar eşit değilse null döner (çağıran tarafça düz
     * Levenshtein'a düşülür).
     *
     * @return array{diff: int, hard: int, soft: int, cost: float}|null
     */
    private function glyphCompare(string $a, string $b): ?array
    {
        if (strlen($a) !== strlen($b)) {
            return null;
        }

        $hard = 0;
        $soft = 0;
        $len = strlen($a);

        for ($i = 0; $i < $len; $i++) {
            if ($a[$i] === $b[$i]) {
                continue;
            }

            if ($this->isConfusableDigit($a[$i], $b[$i])) {
                $soft++;
            } else {
                $hard++;
            }
        }

        return [
            'diff' => $hard + $soft,
            'hard' => $hard,
            'soft' => $soft,
            'cost' => $hard * 1.0 + $soft * self::SOFT_COST,
        ];
    }

    /**
     * İki rakamın, OCR'ın görsel olarak karıştırabileceği bir çift olup olmadığını
     * kontrol eder (çift yönlü).
     */
    private function isConfusableDigit(string $a, string $b): bool
    {
        foreach (self::GLYPH_CONFUSIONS as $pair) {
            if (($pair[0] === $a && $pair[1] === $b) || ($pair[0] === $b && $pair[1] === $a)) {
                return true;
            }
        }

        return false;
    }
}
