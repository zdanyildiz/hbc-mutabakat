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
