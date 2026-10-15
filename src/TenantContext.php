<?php

declare(strict_types=1);

namespace App;

/**
 * Multi-Tenant Bağlam Yönetimi (TenantContext - GP-BIZ-19).
 * Mevcut mutabakat/koli/irsaliye işlemlerinde aktif kiracı (tenant_id) izolasyonunu sağlar.
 */
class TenantContext
{
    private static ?int $tenantId = null;
    private static ?Tenant $tenant = null;
    private static ?TenantSubscription $subscription = null;

    public static function setTenant(Tenant $tenant, ?TenantSubscription $subscription = null): void
    {
        self::$tenant = $tenant;
        self::$tenantId = $tenant->id;
        self::$subscription = $subscription ?? $tenant->subscription;
    }

    public static function setTenantId(?int $tenantId): void
    {
        self::$tenantId = $tenantId;
        if (self::$tenant !== null && self::$tenant->id !== $tenantId) {
            self::$tenant = null;
            self::$subscription = null;
        }
    }

    public static function setSubscription(?TenantSubscription $subscription): void
    {
        self::$subscription = $subscription;
        if (self::$tenant !== null) {
            self::$tenant->subscription = $subscription;
        }
    }

    public static function getTenantId(): ?int
    {
        return self::$tenantId;
    }

    public static function getCurrentTenantId(): ?int
    {
        return self::$tenantId;
    }

    public static function requireTenantId(): int
    {
        if (self::$tenantId === null || self::$tenantId <= 0) {
            throw new SaasQuotaException(
                'Aktif firma (tenant) oturumu bulunamadı.',
                SaasQuotaException::CODE_NO_SUBSCRIPTION
            );
        }

        return self::$tenantId;
    }

    public static function getTenant(): ?Tenant
    {
        return self::$tenant;
    }

    public static function getSubscription(): ?TenantSubscription
    {
        return self::$subscription;
    }

    public static function clear(): void
    {
        self::$tenantId = null;
        self::$tenant = null;
        self::$subscription = null;
    }

    public static function reset(): void
    {
        self::clear();
    }
}
