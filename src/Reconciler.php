<?php

declare(strict_types=1);

namespace App;

class Reconciler
{
    /**
     * Maximum Levenshtein distance for a match to be considered "suspected".
     */
    private const LEVENSHTEIN_THRESHOLD = 2;

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
        $storeBarcodes = [];
        foreach ($pdfPaths as $pdfPath) {
            $extracted = $this->pdfExtractor->extract($pdfPath);
            $storeBarcodes = array_merge($storeBarcodes, $extracted);
        }
        $storeBarcodes = array_values(array_unique($storeBarcodes));

        // Normalize: baştaki sıfırları silerek karşılaştırmaya hazırla
        $termNormMap = [];
        foreach ($terminalBarcodes as $idx => $barcode) {
            $termNormMap[$idx] = ltrim($barcode, '0');
        }

        $storeNormMap = [];
        foreach ($storeBarcodes as $idx => $barcode) {
            $storeNormMap[$idx] = ltrim($barcode, '0');
        }

        // --- Aşama 1: Tam Eşleşme (Exact Match with normalization) ---
        $matched = [];
        $unmatchedTerminalIndices = [];
        $matchedStoreIndices = [];

        foreach ($termNormMap as $tIdx => $tNorm) {
            $found = false;
            foreach ($storeNormMap as $sIdx => $sNorm) {
                if (isset($matchedStoreIndices[$sIdx])) {
                    continue;
                }
                if ($tNorm === $sNorm) {
                    $matched[] = $terminalBarcodes[$tIdx];
                    $matchedStoreIndices[$sIdx] = true;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $unmatchedTerminalIndices[] = $tIdx;
            }
        }

        // --- Aşama 2: Bulanık Eşleşme (Fuzzy Match via Levenshtein) ---
        $suspectedMatches = [];
        $trulyMissingIndices = [];

        foreach ($unmatchedTerminalIndices as $tIdx) {
            $tNorm = $termNormMap[$tIdx];
            $bestDistance = PHP_INT_MAX;
            $bestStoreIdx = -1;

            foreach ($storeNormMap as $sIdx => $sNorm) {
                if (isset($matchedStoreIndices[$sIdx])) {
                    continue;
                }
                $dist = levenshtein($tNorm, $sNorm);
                if ($dist < $bestDistance) {
                    $bestDistance = $dist;
                    $bestStoreIdx = $sIdx;
                }
            }

            if ($bestDistance <= self::LEVENSHTEIN_THRESHOLD && $bestStoreIdx >= 0) {
                $suspectedMatches[] = [
                    'terminal_barcode' => $terminalBarcodes[$tIdx],
                    'store_barcode' => $storeBarcodes[$bestStoreIdx],
                    'distance' => $bestDistance,
                ];
                $matchedStoreIndices[$bestStoreIdx] = true;
            } else {
                $trulyMissingIndices[] = $tIdx;
            }
        }

        // --- Aşama 3: Eksikler ve Fazlalar ---
        $missingInStore = [];
        foreach ($trulyMissingIndices as $tIdx) {
            $missingInStore[] = $terminalBarcodes[$tIdx];
        }

        // Fazlalar: PDF'te olup terminalde eşleşmemiş olanlar
        $extraInStore = [];
        foreach ($storeNormMap as $sIdx => $sNorm) {
            if (!isset($matchedStoreIndices[$sIdx])) {
                $extraInStore[] = $storeBarcodes[$sIdx];
            }
        }

        return new ReconciliationResult(
            $terminalBarcodes,
            $storeBarcodes,
            $matched,
            array_values($missingInStore),
            array_values($extraInStore),
            $suspectedMatches
        );
    }
}
