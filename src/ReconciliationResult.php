<?php

declare(strict_types=1);

namespace App;

class ReconciliationResult
{
    /**
     * @param array<string> $terminalBarcodes
     * @param array<string> $storeBarcodes
     * @param array<string> $matched
     * @param array<string> $missingInStore
     * @param array<string> $extraInStore
     * @param array<array{terminal_barcode: string, store_barcode: string, distance: int}> $suspectedMatches
     * @param array<string> $matchedOcr
     * @param array<string> $matchedText
     * @param array<string> $invalidBarcodes
     */
     public function __construct(
        public readonly array $terminalBarcodes,
        public readonly array $storeBarcodes,
        public readonly array $matched,
        public readonly array $missingInStore,
        public readonly array $extraInStore,
        public readonly array $suspectedMatches = [],
        public readonly array $matchedOcr = [],
        public readonly array $matchedText = [],
        public readonly array $invalidBarcodes = []
    ) {}

    /**
     * Converts the result into an associative array for JSON response.
     *
     * @return array{
     *     terminalCount: int,
     *     storeCount: int,
     *     matchedCount: int,
     *     missingCount: int,
     *     extraCount: int,
     *     suspectedCount: int,
     *     invalidCount: int,
     *     matchedOcrCount: int,
     *     matchedTextCount: int,
     *     matched: array<string>,
     *     missingInStore: array<string>,
     *     extraInStore: array<string>,
     *     suspectedMatches: array<array{terminal_barcode: string, store_barcode: string, distance: int}>,
     *     matchedOcr: array<string>,
     *     matchedText: array<string>,
     *     invalidBarcodes: array<string>,
     *     terminalBarcodes: array<string>,
     *     storeBarcodes: array<string>
     * }
     */
    public function toArray(): array
    {
        $sortFunc = function(string $a, string $b): int {
            $cleanA = (string)preg_replace('/\D/', '', $a);
            $cleanB = (string)preg_replace('/\D/', '', $b);
            $lenA = strlen($cleanA);
            $lenB = strlen($cleanB);
            if ($lenA !== $lenB) {
                return $lenA - $lenB;
            }
            return strcmp($cleanA, $cleanB);
        };

        $terminalBarcodes = $this->terminalBarcodes;
        $storeBarcodes = $this->storeBarcodes;

        usort($terminalBarcodes, $sortFunc);
        usort($storeBarcodes, $sortFunc);

        return [
            'terminalCount' => count($this->terminalBarcodes),
            'storeCount' => count($this->storeBarcodes),
            'matchedCount' => count($this->matched),
            'missingCount' => count($this->missingInStore),
            'extraCount' => count($this->extraInStore),
            'suspectedCount' => count($this->suspectedMatches),
            'invalidCount' => count($this->invalidBarcodes),
            'matchedOcrCount' => count($this->matchedOcr),
            'matchedTextCount' => count($this->matchedText),
            'matched' => $this->matched,
            'missingInStore' => $this->missingInStore,
            'extraInStore' => $this->extraInStore,
            'suspectedMatches' => $this->suspectedMatches,
            'matchedOcr' => $this->matchedOcr,
            'matchedText' => $this->matchedText,
            'invalidBarcodes' => $this->invalidBarcodes,
            'terminalBarcodes' => $terminalBarcodes,
            'storeBarcodes' => $storeBarcodes,
        ];
    }
}
