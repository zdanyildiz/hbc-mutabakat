<?php

declare(strict_types=1);

namespace App;

/**
 * Multi-Tenant OCR ve Mutabakat Kota Kullanım Kaydı Modeli (GP-BIZ-19).
 */
class TenantUsage
{
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $subscriptionId,
        public readonly ?int $reconciliationId,
        public readonly string $storeName,
        public readonly int $ocrUnits,
        public readonly string $createdAt = ''
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) && is_numeric($row['id']) ? (int)$row['id'] : 0,
            tenantId: isset($row['tenant_id']) && is_numeric($row['tenant_id']) ? (int)$row['tenant_id'] : 0,
            subscriptionId: isset($row['subscription_id']) && is_numeric($row['subscription_id']) ? (int)$row['subscription_id'] : 0,
            reconciliationId: isset($row['reconciliation_id']) && is_numeric($row['reconciliation_id']) ? (int)$row['reconciliation_id'] : null,
            storeName: isset($row['store_name']) && is_scalar($row['store_name']) ? (string)$row['store_name'] : '',
            ocrUnits: isset($row['ocr_units']) && is_numeric($row['ocr_units']) ? (int)$row['ocr_units'] : 1,
            createdAt: isset($row['created_at']) && is_scalar($row['created_at']) ? (string)$row['created_at'] : ''
        );
    }

    /**
     * @return array{
     *     id: int,
     *     tenant_id: int,
     *     subscription_id: int,
     *     reconciliation_id: int|null,
     *     store_name: string,
     *     ocr_units: int,
     *     created_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'subscription_id' => $this->subscriptionId,
            'reconciliation_id' => $this->reconciliationId,
            'store_name' => $this->storeName,
            'ocr_units' => $this->ocrUnits,
            'created_at' => $this->createdAt,
        ];
    }
}
