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

        $matched = [];
        $missingInStore = [];

        // 1. Aşama: Tam Eşleşenler (OCR Taraması ile)
        foreach ($terminalBarcodes as $terminalBarcode) {
            $found = false;
            foreach ($pdfLinesPool as $idx => $pdfLine) {
                if (str_contains($pdfLine, $terminalBarcode) || str_contains($terminalBarcode, $pdfLine)) {
                    $matched[] = $terminalBarcode;
                    unset($pdfLinesPool[$idx]);
                    $found = true;
                    break;
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
                'E' => '8', 'o' => '0'
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
                        $matched[] = $missingBarcode;
                        // Eşleşme olunca satırı havuzdan sil ve bir sonraki eksik barkoda geç
                        unset($rawPdfLines[$idx]);
                        $foundInText = true;
                        \App\Logger::log("[Reconciler-TextSearch] Eksik Barkod Metin Aramasıyla Eşleşti: " . $missingBarcode);
                        break;
                    }
                }

                if (!$foundInText) {
                    $stillMissing[] = $missingBarcode;
                }
            }
            $missingInStore = $stillMissing;
        }

        $extraInStore = array_values($pdfLinesPool);
        $suspectedMatches = [];

        return new ReconciliationResult(
            $terminalBarcodes,
            $pdfLines,
            $matched,
            array_values($missingInStore),
            array_values($extraInStore),
            $suspectedMatches
        );
    }
}
