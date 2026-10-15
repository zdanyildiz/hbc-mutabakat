<?php

declare(strict_types=1);

namespace App;

/**
 * Multi-Tenant SaaS Firma (Tenant) Modeli (GP-BIZ-19).
 *
 * @implements \ArrayAccess<string, mixed>
 */
class Tenant implements \ArrayAccess
{
    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_SUSPENDED = 'suspended';

    public ?TenantSubscription $subscription = null;

    public function __construct(
        public readonly int $id,
        public readonly string $companyName,
        public readonly string $slug,
        public readonly ?string $taxNumber,
        public readonly string $contactName,
        public readonly string $contactEmail,
        public readonly ?string $contactPhone,
        public string $status = self::STATUS_TRIAL,
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
            companyName: isset($row['company_name']) && is_scalar($row['company_name']) ? (string)$row['company_name'] : '',
            slug: isset($row['slug']) && is_scalar($row['slug']) ? (string)$row['slug'] : '',
            taxNumber: isset($row['tax_number']) && is_scalar($row['tax_number']) && (string)$row['tax_number'] !== '' ? (string)$row['tax_number'] : null,
            contactName: isset($row['contact_name']) && is_scalar($row['contact_name']) ? (string)$row['contact_name'] : '',
            contactEmail: isset($row['contact_email']) && is_scalar($row['contact_email']) ? (string)$row['contact_email'] : '',
            contactPhone: isset($row['contact_phone']) && is_scalar($row['contact_phone']) && (string)$row['contact_phone'] !== '' ? (string)$row['contact_phone'] : null,
            status: isset($row['status']) && is_scalar($row['status']) ? (string)$row['status'] : self::STATUS_TRIAL,
            createdAt: isset($row['created_at']) && is_scalar($row['created_at']) ? (string)$row['created_at'] : '',
            updatedAt: isset($row['updated_at']) && is_scalar($row['updated_at']) ? (string)$row['updated_at'] : ''
        );
    }

    public function isActiveOrTrial(): bool
    {
        return $this->status === self::STATUS_TRIAL || $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @return array{
     *     id: int,
     *     company_name: string,
     *     slug: string,
     *     tax_number: string|null,
     *     contact_name: string,
     *     contact_email: string,
     *     contact_phone: string|null,
     *     status: string,
     *     created_at: string,
     *     updated_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'company_name' => $this->companyName,
            'slug' => $this->slug,
            'tax_number' => $this->taxNumber,
            'contact_name' => $this->contactName,
            'contact_email' => $this->contactEmail,
            'contact_phone' => $this->contactPhone,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'company_name' => $this->companyName,
            'tax_number' => $this->taxNumber,
            'contact_name' => $this->contactName,
            'contact_email' => $this->contactEmail,
            'contact_phone' => $this->contactPhone,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'tenant' => $this,
            default => null,
        };
    }

    public function offsetExists(mixed $offset): bool
    {
        if (!is_string($offset)) {
            return false;
        }
        $data = $this->toArray();
        return array_key_exists($offset, $data)
            || in_array($offset, ['tenant', 'subscription', 'plan_code', 'monthly_ocr_quota', 'used_ocr_count'], true);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!is_string($offset)) {
            return null;
        }
        if ($offset === 'tenant') {
            return $this;
        }
        if ($offset === 'subscription') {
            return $this->subscription;
        }
        $data = $this->toArray();
        if (array_key_exists($offset, $data)) {
            return $data[$offset];
        }
        if ($this->subscription !== null && $this->subscription->offsetExists($offset)) {
            return $this->subscription->offsetGet($offset);
        }
        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === 'status' && is_string($value)) {
            $this->status = $value;
        } elseif ($offset === 'subscription' && ($value instanceof TenantSubscription || $value === null)) {
            $this->subscription = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        if ($offset === 'subscription') {
            $this->subscription = null;
        }
    }
}
