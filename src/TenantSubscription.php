<?php

declare(strict_types=1);

namespace App;

/**
 * Multi-Tenant SaaS Abonelik ve OCR Kota Modeli (GP-BIZ-19).
 *
 * @implements \ArrayAccess<string, mixed>
 */
class TenantSubscription implements \ArrayAccess
{
    public const STATUS_TRIAL = 'trial';
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public ?Tenant $tenant = null;

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public string $planCode,
        public string $billingCycle,
        public float $priceTry,
        public int $monthlyOcrQuota,
        public int $usedOcrCount,
        public ?string $trialEndsAt,
        public string $startsAt,
        public string $endsAt,
        public string $status = self::STATUS_TRIAL,
        public ?string $paymentReference = null,
        public readonly string $createdAt = '',
        public string $updatedAt = ''
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) && is_numeric($row['id']) ? (int)$row['id'] : 0,
            tenantId: isset($row['tenant_id']) && is_numeric($row['tenant_id']) ? (int)$row['tenant_id'] : 0,
            planCode: isset($row['plan_code']) && is_scalar($row['plan_code']) ? (string)$row['plan_code'] : SaasPlanCatalog::PLAN_TRIAL,
            billingCycle: isset($row['billing_cycle']) && is_scalar($row['billing_cycle']) ? (string)$row['billing_cycle'] : SaasPlanCatalog::CYCLE_MONTHLY,
            priceTry: isset($row['price_try']) && is_numeric($row['price_try']) ? (float)$row['price_try'] : 0.0,
            monthlyOcrQuota: isset($row['monthly_ocr_quota']) && is_numeric($row['monthly_ocr_quota']) ? (int)$row['monthly_ocr_quota'] : SaasPlanCatalog::TRIAL_OCR_QUOTA,
            usedOcrCount: isset($row['used_ocr_count']) && is_numeric($row['used_ocr_count']) ? (int)$row['used_ocr_count'] : 0,
            trialEndsAt: isset($row['trial_ends_at']) && is_scalar($row['trial_ends_at']) && (string)$row['trial_ends_at'] !== '' ? (string)$row['trial_ends_at'] : null,
            startsAt: isset($row['starts_at']) && is_scalar($row['starts_at']) ? (string)$row['starts_at'] : '',
            endsAt: isset($row['ends_at']) && is_scalar($row['ends_at']) ? (string)$row['ends_at'] : '',
            status: isset($row['status']) && is_scalar($row['status']) ? (string)$row['status'] : self::STATUS_TRIAL,
            paymentReference: isset($row['payment_reference']) && is_scalar($row['payment_reference']) && (string)$row['payment_reference'] !== '' ? (string)$row['payment_reference'] : null,
            createdAt: isset($row['created_at']) && is_scalar($row['created_at']) ? (string)$row['created_at'] : '',
            updatedAt: isset($row['updated_at']) && is_scalar($row['updated_at']) ? (string)$row['updated_at'] : ''
        );
    }

    public function isUnlimited(): bool
    {
        return $this->monthlyOcrQuota < 0 || $this->planCode === SaasPlanCatalog::PLAN_ENTERPRISE_UNLIMITED;
    }

    public function remainingQuota(): ?int
    {
        if ($this->isUnlimited()) {
            return null;
        }

        return max(0, $this->monthlyOcrQuota - $this->usedOcrCount);
    }

    public function hasQuotaFor(int $units = 1): bool
    {
        if ($units <= 0) {
            return false;
        }
        if ($this->isUnlimited()) {
            return true;
        }

        return ($this->usedOcrCount + $units) <= $this->monthlyOcrQuota;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if ($this->status === self::STATUS_EXPIRED || $this->status === self::STATUS_CANCELLED) {
            return true;
        }

        $current = $now ?? new \DateTimeImmutable('now');

        if ($this->status === self::STATUS_TRIAL && $this->trialEndsAt !== null && $this->trialEndsAt !== '') {
            try {
                $trialEnd = new \DateTimeImmutable($this->trialEndsAt);
                if ($current > $trialEnd) {
                    return true;
                }
            } catch (\Exception) {
                return true;
            }
        }

        if ($this->endsAt === '') {
            return true;
        }

        try {
            $end = new \DateTimeImmutable($this->endsAt);
            return $current > $end;
        } catch (\Exception) {
            return true;
        }
    }

    public function canProcessOcr(int $units = 1, ?\DateTimeImmutable $now = null): bool
    {
        if ($this->status !== self::STATUS_TRIAL && $this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($this->isExpired($now)) {
            return false;
        }

        return $this->hasQuotaFor($units);
    }

    /**
     * @return array{
     *     id: int,
     *     tenant_id: int,
     *     plan_code: string,
     *     billing_cycle: string,
     *     price_try: float,
     *     monthly_ocr_quota: int,
     *     used_ocr_count: int,
     *     remaining_ocr_quota: int|null,
     *     is_unlimited: bool,
     *     trial_ends_at: string|null,
     *     starts_at: string,
     *     ends_at: string,
     *     status: string,
     *     payment_reference: string|null,
     *     created_at: string,
     *     updated_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'plan_code' => $this->planCode,
            'billing_cycle' => $this->billingCycle,
            'price_try' => $this->priceTry,
            'monthly_ocr_quota' => $this->monthlyOcrQuota,
            'used_ocr_count' => $this->usedOcrCount,
            'remaining_ocr_quota' => $this->remainingQuota(),
            'is_unlimited' => $this->isUnlimited(),
            'trial_ends_at' => $this->trialEndsAt,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'status' => $this->status,
            'payment_reference' => $this->paymentReference,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'tenant_id' => $this->tenantId,
            'plan_code' => $this->planCode,
            'billing_cycle' => $this->billingCycle,
            'price_try' => $this->priceTry,
            'monthly_ocr_quota' => $this->monthlyOcrQuota,
            'used_ocr_count' => $this->usedOcrCount,
            'remaining_ocr_quota' => $this->remainingQuota(),
            'trial_ends_at' => $this->trialEndsAt,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'payment_reference' => $this->paymentReference,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'subscription' => $this,
            default => null,
        };
    }

    public function offsetExists(mixed $offset): bool
    {
        if (!is_string($offset)) {
            return false;
        }
        $data = $this->toArray();
        return array_key_exists($offset, $data) || in_array($offset, ['tenant', 'subscription'], true);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!is_string($offset)) {
            return null;
        }
        if ($offset === 'subscription') {
            return $this;
        }
        if ($offset === 'tenant') {
            return $this->tenant;
        }
        $data = $this->toArray();
        return $data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === 'tenant' && ($value instanceof Tenant || $value === null)) {
            $this->tenant = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        if ($offset === 'tenant') {
            $this->tenant = null;
        }
    }
}
