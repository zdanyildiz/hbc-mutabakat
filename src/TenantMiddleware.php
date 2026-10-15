<?php

declare(strict_types=1);

namespace App;

/**
 * Multi-Tenant İstek ve Bağlam Çözümleyici Middleware (GP-BIZ-19).
 */
class TenantMiddleware
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService
    ) {}

    /**
     * İstekten (Header, POST, GET, Session) aktif kiracıyı çözümler ve TenantContext'e bağlar.
     *
     * @param array<string, mixed> $requestParams
     * @param array<string, mixed> $serverParams
     * @param array<string, mixed> $sessionParams
     */
    public function resolve(
        array $requestParams = [],
        array $serverParams = [],
        array $sessionParams = []
    ): ?Tenant {
        $tenantId = null;
        $tenantSlug = null;

        if (isset($serverParams['HTTP_X_TENANT_ID']) && is_numeric($serverParams['HTTP_X_TENANT_ID'])) {
            $tenantId = (int)$serverParams['HTTP_X_TENANT_ID'];
        } elseif (isset($requestParams['tenant_id']) && is_numeric($requestParams['tenant_id'])) {
            $tenantId = (int)$requestParams['tenant_id'];
        } elseif (isset($sessionParams['tenant_id']) && is_numeric($sessionParams['tenant_id'])) {
            $tenantId = (int)$sessionParams['tenant_id'];
        }

        if (isset($serverParams['HTTP_X_TENANT_SLUG']) && is_scalar($serverParams['HTTP_X_TENANT_SLUG'])) {
            $tenantSlug = trim((string)$serverParams['HTTP_X_TENANT_SLUG']);
        } elseif (isset($requestParams['tenant_slug']) && is_scalar($requestParams['tenant_slug'])) {
            $tenantSlug = trim((string)$requestParams['tenant_slug']);
        }

        $tenant = null;
        if ($tenantId !== null && $tenantId > 0) {
            $tenant = $this->subscriptionService->getTenantById($tenantId);
        } elseif ($tenantSlug !== null && $tenantSlug !== '') {
            $tenant = $this->subscriptionService->getTenantBySlug($tenantSlug);
        }

        if ($tenant !== null) {
            $subscription = $this->subscriptionService->getCurrentSubscription($tenant->id);
            $tenant->subscription = $subscription;
            if ($subscription !== null) {
                $subscription->tenant = $tenant;
            }
            TenantContext::setTenant($tenant, $subscription);
            return $tenant;
        }

        return null;
    }

    /**
     * Aktif bağlamdaki tenant için OCR ve abonelik kota kapısını (fail-closed) denetler.
     */
    public function enforceOcrQuota(int $requestedUnits = 1, ?\DateTimeImmutable $now = null): TenantSubscription
    {
        $tenantId = TenantContext::requireTenantId();
        return $this->subscriptionService->assertCanProcessOcr($tenantId, $requestedUnits, $now);
    }
}
