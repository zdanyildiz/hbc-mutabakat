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
     * Reconciles barcodes between an Excel/CSV file and a PDF file.
     *
     * @param string $excelPath
     * @param string $pdfPath
     * @return ReconciliationResult
     */
    public function reconcile(string $excelPath, string $pdfPath): ReconciliationResult
    {
        $terminalBarcodes = $this->excelExtractor->extract($excelPath);
        $storeBarcodes = $this->pdfExtractor->extract($pdfPath);

        // Terminalde olup, mağaza çıktısında OLMAYAN barkodlar (Eksik)
        $missingInStore = array_values(array_diff($terminalBarcodes, $storeBarcodes));

        // Fazladan mağaza çıktısında olup, terminalde OLMAYAN (Fazla)
        $extraInStore = array_values(array_diff($storeBarcodes, $terminalBarcodes));

        // Her iki listede de olanlar (Eşleşen)
        $matched = array_values(array_intersect($terminalBarcodes, $storeBarcodes));

        return new ReconciliationResult(
            $terminalBarcodes,
            $storeBarcodes,
            $matched,
            $missingInStore,
            $extraInStore
        );
    }
}
