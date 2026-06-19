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
     */
    public function __construct(
        public readonly array $terminalBarcodes,
        public readonly array $storeBarcodes,
        public readonly array $matched,
        public readonly array $missingInStore,
        public readonly array $extraInStore
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
     *     matched: array<string>,
     *     missingInStore: array<string>,
     *     extraInStore: array<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'terminalCount' => count($this->terminalBarcodes),
            'storeCount' => count($this->storeBarcodes),
            'matchedCount' => count($this->matched),
            'missingCount' => count($this->missingInStore),
            'extraCount' => count($this->extraInStore),
            'matched' => $this->matched,
            'missingInStore' => $this->missingInStore,
            'extraInStore' => $this->extraInStore,
        ];
    }
}
