<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Multi-Tenant SaaS Üyelik, Kota Denetimi (Quota Gate) ve Abonelik Servisi (GP-BIZ-19).
 */
class SubscriptionService
{
    private PDO $pdo;

    public function __construct(
        Database|PDO $database,
        private readonly string $approvalSecret = ''
    ) {
        if ($database instanceof Database) {
            $conn = $database->getSaasConnection();
            $this->pdo = $conn;
            $database->initSchema($this->pdo);
        } else {
            $this->pdo = $database;
            $dbHelper = new Database(['enabled' => true], $this->pdo);
            $dbHelper->initSchema($this->pdo);
        }
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Yeni tedarikçi/lojistik firması kaydı: Otomatik 90 gün ve 50 OCR kotalı trial abonelik başlatır.
     *
     * @param array<string, mixed> $payload
     * @param \DateTimeImmutable|null $now
     * @return Tenant
     */
    public function registerTenantWithTrial(array $payload, ?\DateTimeImmutable $now = null): Tenant
    {
        $companyName = $this->extractString($payload, ['company_name', 'companyName']);
        $contactName = $this->extractString($payload, ['contact_name', 'contactName']);
        $contactEmail = $this->extractString($payload, ['contact_email', 'contactEmail', 'email']);
        $taxNumber = $this->extractOptionalString($payload, ['tax_number', 'taxNumber']);
        $contactPhone = $this->extractOptionalString($payload, ['contact_phone', 'contactPhone', 'phone']);
        $requestedSlug = $this->extractOptionalString($payload, ['slug']);

        if ($companyName === '') {
            throw new \InvalidArgumentException('Firma adı (company_name) zorunludur.');
        }

        if ($contactName === '') {
            $contactName = $companyName;
        }

        if ($contactEmail === '' || filter_var($contactEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Geçerli bir iletişim e-posta adresi (contact_email) zorunludur.');
        }

        $slug = $this->generateUniqueSlug($requestedSlug ?? $companyName);
        $current = $now ?? new \DateTimeImmutable('now');
        $nowStr = $current->format('Y-m-d H:i:s');
        $trialEndStr = $current->modify('+' . SaasPlanCatalog::TRIAL_DAYS . ' days')->format('Y-m-d H:i:s');

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmtTenant = $this->pdo->prepare(
                'INSERT INTO tenants (company_name, slug, tax_number, contact_name, contact_email, contact_phone, status, created_at, updated_at)
                 VALUES (:company_name, :slug, :tax_number, :contact_name, :contact_email, :contact_phone, :status, :created_at, :updated_at)'
            );
            $stmtTenant->execute([
                'company_name' => $companyName,
                'slug' => $slug,
                'tax_number' => $taxNumber,
                'contact_name' => $contactName,
                'contact_email' => $contactEmail,
                'contact_phone' => $contactPhone,
                'status' => Tenant::STATUS_TRIAL,
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);

            $tenantId = (int)$this->pdo->lastInsertId();

            $stmtSub = $this->pdo->prepare(
                'INSERT INTO tenant_subscriptions
                 (tenant_id, plan_code, billing_cycle, price_try, monthly_ocr_quota, used_ocr_count, trial_ends_at, starts_at, ends_at, status, payment_reference, created_at, updated_at)
                 VALUES (:tenant_id, :plan_code, :billing_cycle, :price_try, :monthly_ocr_quota, :used_ocr_count, :trial_ends_at, :starts_at, :ends_at, :status, :payment_reference, :created_at, :updated_at)'
            );
            $stmtSub->execute([
                'tenant_id' => $tenantId,
                'plan_code' => SaasPlanCatalog::PLAN_TRIAL,
                'billing_cycle' => SaasPlanCatalog::CYCLE_MONTHLY,
                'price_try' => 0.0,
                'monthly_ocr_quota' => SaasPlanCatalog::TRIAL_OCR_QUOTA,
                'used_ocr_count' => 0,
                'trial_ends_at' => $trialEndStr,
                'starts_at' => $nowStr,
                'ends_at' => $trialEndStr,
                'status' => TenantSubscription::STATUS_TRIAL,
                'payment_reference' => null,
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);

            $subId = (int)$this->pdo->lastInsertId();

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $tenant = $this->getTenantById($tenantId);
        $subscription = $this->getSubscriptionById($subId);

        if ($tenant === null || $subscription === null) {
            throw new \RuntimeException('Kayıt sonrası firma veya abonelik bilgisi okunamadı.');
        }

        $tenant->subscription = $subscription;
        $subscription->tenant = $tenant;

        TenantContext::setTenant($tenant, $subscription);

        return $tenant;
    }

    /**
     * OCR / Koli mutabakat işlemi öncesi kota ve abonelik süresi kontrolü (Fail-Closed Quota Gate).
     * Süresi dolmuş, ödemesi bekleyen veya kotası bitmiş tenant'ı durdurur.
     */
    public function assertCanProcessOcr(
        int $tenantId,
        int $requestedUnits = 1,
        ?\DateTimeImmutable $now = null
    ): TenantSubscription {
        if ($requestedUnits <= 0) {
            throw new \InvalidArgumentException('OCR kullanım birimi (requestedUnits) en az 1 olmalıdır.');
        }

        $tenant = $this->getTenantById($tenantId);
        if ($tenant === null) {
            throw new SaasQuotaException(
                'Geçerli bir firma (tenant) kaydı bulunamadı. Lütfen ücretsiz deneme hesabı oluşturun.',
                SaasQuotaException::CODE_NO_SUBSCRIPTION,
                true,
                $tenantId
            );
        }

        if ($tenant->status === Tenant::STATUS_SUSPENDED) {
            throw new SaasQuotaException(
                'Firma hesabınız askıya alınmıştır. Lütfen destek ekibiyle iletişime geçin.',
                SaasQuotaException::CODE_TENANT_SUSPENDED,
                true,
                $tenantId
            );
        }

        $subscriptions = $this->getSubscriptionsForTenant($tenantId);
        if ($subscriptions === []) {
            throw new SaasQuotaException(
                'Firmanıza tanımlı abonelik bulunamadı. Lütfen paket seçimi yapın.',
                SaasQuotaException::CODE_NO_SUBSCRIPTION,
                true,
                $tenantId
            );
        }

        $activeOrTrialSub = null;
        $hasPendingPayment = ($tenant->status === Tenant::STATUS_PENDING_PAYMENT);

        foreach ($subscriptions as $sub) {
            if ($sub->status === TenantSubscription::STATUS_PENDING_PAYMENT) {
                $hasPendingPayment = true;
            }
            if ($activeOrTrialSub === null && (
                $sub->status === TenantSubscription::STATUS_ACTIVE
                || $sub->status === TenantSubscription::STATUS_TRIAL
            )) {
                $activeOrTrialSub = $sub;
            }
        }

        if ($activeOrTrialSub === null) {
            if ($hasPendingPayment) {
                throw new SaasQuotaException(
                    'Paket yükseltme talebiniz ödeme onayı bekliyor (pending_payment). Ödeme onaylandıktan sonra OCR kotanız aktifleşecektir.',
                    SaasQuotaException::CODE_PENDING_PAYMENT,
                    true,
                    $tenantId
                );
            }

            throw new SaasQuotaException(
                'Aktif bir aboneliğiniz bulunmuyor veya abonelik süreniz sona erdi. İşleme devam etmek için lütfen paket yükseltin.',
                SaasQuotaException::CODE_SUBSCRIPTION_EXPIRED,
                true,
                $tenantId
            );
        }

        $current = $now ?? new \DateTimeImmutable('now');

        if ($activeOrTrialSub->isExpired($current)) {
            $this->markSubscriptionExpired($activeOrTrialSub->id, $current);
            throw new SaasQuotaException(
                'Abonelik veya 90 günlük ücretsiz deneme süreniz sona erdi. İşleme devam etmek için lütfen paket yükseltin.',
                SaasQuotaException::CODE_SUBSCRIPTION_EXPIRED,
                true,
                $tenantId
            );
        }

        if (!$activeOrTrialSub->hasQuotaFor($requestedUnits)) {
            throw new SaasQuotaException(
                sprintf(
                    'Aylık OCR koli/irsaliye okuma kotanız doldu (%d / %d kullanıldı, talep edilen: %d). İşleme devam etmek için lütfen paket yükseltin.',
                    $activeOrTrialSub->usedOcrCount,
                    $activeOrTrialSub->monthlyOcrQuota,
                    $requestedUnits
                ),
                SaasQuotaException::CODE_QUOTA_EXCEEDED,
                true,
                $tenantId
            );
        }

        $activeOrTrialSub->tenant = $tenant;
        return $activeOrTrialSub;
    }

    /**
     * OCR / Koli mutabakat işlemi için kota tüketir ve kullanım kaydı (TenantUsage) oluşturur.
     */
    public function consumeOcrQuota(
        int $tenantId,
        int $units = 1,
        string $storeName = 'Mutabakat',
        ?int $reconciliationId = null,
        ?\DateTimeImmutable $now = null
    ): TenantSubscription {
        $sub = $this->assertCanProcessOcr($tenantId, $units, $now);
        $current = $now ?? new \DateTimeImmutable('now');
        $nowStr = $current->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'UPDATE tenant_subscriptions
             SET used_ocr_count = used_ocr_count + :units,
                 updated_at = :updated_at
             WHERE id = :id
               AND (monthly_ocr_quota < 0 OR (used_ocr_count + :units_check) <= monthly_ocr_quota)'
        );
        $stmt->execute([
            'units' => $units,
            'updated_at' => $nowStr,
            'id' => $sub->id,
            'units_check' => $units,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new SaasQuotaException(
                'Aylık OCR okuma kotanız yetersiz olduğu için işlem durduruldu.',
                SaasQuotaException::CODE_QUOTA_EXCEEDED,
                true,
                $tenantId
            );
        }

        $stmtUsage = $this->pdo->prepare(
            'INSERT INTO tenant_usages (tenant_id, subscription_id, reconciliation_id, store_name, ocr_units, created_at)
             VALUES (:tenant_id, :subscription_id, :reconciliation_id, :store_name, :ocr_units, :created_at)'
        );
        $stmtUsage->execute([
            'tenant_id' => $tenantId,
            'subscription_id' => $sub->id,
            'reconciliation_id' => $reconciliationId,
            'store_name' => $storeName !== '' ? $storeName : 'Mutabakat',
            'ocr_units' => $units,
            'created_at' => $nowStr,
        ]);

        $updatedSub = $this->getSubscriptionById($sub->id);
        if ($updatedSub === null) {
            throw new \RuntimeException('Kota güncellendikten sonra abonelik kaydı okunamadı.');
        }

        $tenant = $this->getTenantById($tenantId);
        if ($tenant !== null) {
            $updatedSub->tenant = $tenant;
            $tenant->subscription = $updatedSub;
            if (TenantContext::getTenantId() === $tenantId) {
                TenantContext::setTenant($tenant, $updatedSub);
            }
        }

        return $updatedSub;
    }

    /**
     * Paket yükseltme talebi oluşturur.
     * Ödemesiz doğrudan aktivasyonu engeller, pending_payment kaydı açar.
     */
    public function requestPlanUpgrade(
        int $tenantId,
        string $planCode,
        string $billingCycle = SaasPlanCatalog::CYCLE_YEARLY,
        ?string $paymentReference = null,
        ?\DateTimeImmutable $now = null
    ): TenantSubscription {
        if ($planCode === SaasPlanCatalog::PLAN_TRIAL) {
            throw new \InvalidArgumentException('Deneme (trial) paketine yükseltme talebi açılamaz.');
        }

        $tenant = $this->getTenantById($tenantId);
        if ($tenant === null) {
            throw new \InvalidArgumentException("Firma bulunamadı: {$tenantId}");
        }

        if ($tenant->status === Tenant::STATUS_SUSPENDED) {
            throw new SaasQuotaException(
                'Askıya alınmış firma için paket yükseltme yapılamaz.',
                SaasQuotaException::CODE_TENANT_SUSPENDED,
                false,
                $tenantId
            );
        }

        $priceTry = SaasPlanCatalog::resolvePriceTry($planCode, $billingCycle);
        $monthlyQuota = SaasPlanCatalog::resolveMonthlyQuota($planCode);

        $current = $now ?? new \DateTimeImmutable('now');
        $nowStr = $current->format('Y-m-d H:i:s');
        $endsAt = $billingCycle === SaasPlanCatalog::CYCLE_YEARLY
            ? $current->modify('+1 year')->format('Y-m-d H:i:s')
            : $current->modify('+1 month')->format('Y-m-d H:i:s');

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            // Cancel any previous pending_payment requests and supersede trial/active subscriptions
            // so the upgrade cannot be bypassed or auto-activated without payment approval.
            $stmtCancelOld = $this->pdo->prepare(
                'UPDATE tenant_subscriptions
                 SET status = :cancelled_status, updated_at = :updated_at
                 WHERE tenant_id = :tenant_id
                   AND status IN (:status_pending, :status_trial, :status_active)'
            );
            $stmtCancelOld->execute([
                'cancelled_status' => TenantSubscription::STATUS_CANCELLED,
                'updated_at' => $nowStr,
                'tenant_id' => $tenantId,
                'status_pending' => TenantSubscription::STATUS_PENDING_PAYMENT,
                'status_trial' => TenantSubscription::STATUS_TRIAL,
                'status_active' => TenantSubscription::STATUS_ACTIVE,
            ]);

            $stmtUpdateTenant = $this->pdo->prepare(
                'UPDATE tenants SET status = :status, updated_at = :updated_at WHERE id = :id'
            );
            $stmtUpdateTenant->execute([
                'status' => Tenant::STATUS_PENDING_PAYMENT,
                'updated_at' => $nowStr,
                'id' => $tenantId,
            ]);

            $stmtInsert = $this->pdo->prepare(
                'INSERT INTO tenant_subscriptions
                 (tenant_id, plan_code, billing_cycle, price_try, monthly_ocr_quota, used_ocr_count, trial_ends_at, starts_at, ends_at, status, payment_reference, created_at, updated_at)
                 VALUES (:tenant_id, :plan_code, :billing_cycle, :price_try, :monthly_ocr_quota, :used_ocr_count, :trial_ends_at, :starts_at, :ends_at, :status, :payment_reference, :created_at, :updated_at)'
            );
            $stmtInsert->execute([
                'tenant_id' => $tenantId,
                'plan_code' => $planCode,
                'billing_cycle' => $billingCycle,
                'price_try' => $priceTry,
                'monthly_ocr_quota' => $monthlyQuota,
                'used_ocr_count' => 0,
                'trial_ends_at' => null,
                'starts_at' => $nowStr,
                'ends_at' => $endsAt,
                'status' => TenantSubscription::STATUS_PENDING_PAYMENT,
                'payment_reference' => $paymentReference,
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);

            $newSubId = (int)$this->pdo->lastInsertId();

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $pendingSub = $this->getSubscriptionById($newSubId);
        $updatedTenant = $this->getTenantById($tenantId);
        if ($pendingSub === null || $updatedTenant === null) {
            throw new \RuntimeException('Paket yükseltme talebi kaydedilemedi.');
        }

        $pendingSub->tenant = $updatedTenant;
        $updatedTenant->subscription = $pendingSub;

        if (TenantContext::getTenantId() === $tenantId) {
            TenantContext::setTenant($updatedTenant, $pendingSub);
        }

        return $pendingSub;
    }

    /**
     * Ödeme/havale veya admin/HMAC onayıyla pending_payment aboneliği active yapar ve kotayı sıfırlayıp/yükseltir.
     * Hem subscription_id hem de tenant_id ile çağrılabilir.
     */
    public function approvePlanUpgrade(
        int $subscriptionOrTenantId,
        ?string $paymentReference = null,
        ?string $hmacSignature = null,
        ?\DateTimeImmutable $now = null
    ): TenantSubscription {
        if ($this->approvalSecret !== '' && $hmacSignature !== null) {
            $expected = hash_hmac('sha256', (string)$subscriptionOrTenantId, $this->approvalSecret);
            if (!hash_equals($expected, $hmacSignature)) {
                throw new \RuntimeException('Geçersiz ödeme/admin HMAC onay imzası.');
            }
        }

        $pendingSub = $this->findPendingSubscription($subscriptionOrTenantId);
        if ($pendingSub === null) {
            throw new \RuntimeException("Onay bekleyen (pending_payment) abonelik talebi bulunamadı: {$subscriptionOrTenantId}");
        }

        $current = $now ?? new \DateTimeImmutable('now');
        $nowStr = $current->format('Y-m-d H:i:s');
        $endsAt = $pendingSub->billingCycle === SaasPlanCatalog::CYCLE_YEARLY
            ? $current->modify('+1 year')->format('Y-m-d H:i:s')
            : $current->modify('+1 month')->format('Y-m-d H:i:s');

        $newQuota = SaasPlanCatalog::resolveMonthlyQuota($pendingSub->planCode);
        $finalPaymentRef = $paymentReference !== null && $paymentReference !== ''
            ? $paymentReference
            : ($pendingSub->paymentReference ?? ('APPROVED-' . $current->format('YmdHis')));

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            // Cancel any other active/trial/pending subscriptions for this tenant
            $stmtCancelOthers = $this->pdo->prepare(
                'UPDATE tenant_subscriptions
                 SET status = :cancelled_status, updated_at = :updated_at
                 WHERE tenant_id = :tenant_id AND id != :id AND status IN (:s_trial, :s_active, :s_pending)'
            );
            $stmtCancelOthers->execute([
                'cancelled_status' => TenantSubscription::STATUS_CANCELLED,
                'updated_at' => $nowStr,
                'tenant_id' => $pendingSub->tenantId,
                'id' => $pendingSub->id,
                's_trial' => TenantSubscription::STATUS_TRIAL,
                's_active' => TenantSubscription::STATUS_ACTIVE,
                's_pending' => TenantSubscription::STATUS_PENDING_PAYMENT,
            ]);

            // Activate the upgraded subscription and reset used OCR quota to 0
            $stmtApprove = $this->pdo->prepare(
                'UPDATE tenant_subscriptions
                 SET status = :status,
                     monthly_ocr_quota = :monthly_ocr_quota,
                     used_ocr_count = 0,
                     starts_at = :starts_at,
                     ends_at = :ends_at,
                     payment_reference = :payment_reference,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmtApprove->execute([
                'status' => TenantSubscription::STATUS_ACTIVE,
                'monthly_ocr_quota' => $newQuota,
                'starts_at' => $nowStr,
                'ends_at' => $endsAt,
                'payment_reference' => $finalPaymentRef,
                'updated_at' => $nowStr,
                'id' => $pendingSub->id,
            ]);

            // Activate the tenant
            $stmtTenant = $this->pdo->prepare(
                'UPDATE tenants SET status = :status, updated_at = :updated_at WHERE id = :id'
            );
            $stmtTenant->execute([
                'status' => Tenant::STATUS_ACTIVE,
                'updated_at' => $nowStr,
                'id' => $pendingSub->tenantId,
            ]);

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $approvedSub = $this->getSubscriptionById($pendingSub->id);
        $updatedTenant = $this->getTenantById($pendingSub->tenantId);
        if ($approvedSub === null || $updatedTenant === null) {
            throw new \RuntimeException('Onay sonrası abonelik kaydı okunamadı.');
        }

        $approvedSub->tenant = $updatedTenant;
        $updatedTenant->subscription = $approvedSub;

        if (TenantContext::getTenantId() === $updatedTenant->id) {
            TenantContext::setTenant($updatedTenant, $approvedSub);
        }

        return $approvedSub;
    }

    public function generateApprovalHmac(int $subscriptionOrTenantId): string
    {
        return hash_hmac('sha256', (string)$subscriptionOrTenantId, $this->approvalSecret);
    }

    public function getTenantById(int $tenantId): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $tenantId]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return Tenant::fromArray($row);
    }

    public function getTenantBySlug(string $slug): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tenants WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return Tenant::fromArray($row);
    }

    public function getSubscriptionById(int $subscriptionId): ?TenantSubscription
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tenant_subscriptions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $subscriptionId]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return TenantSubscription::fromArray($row);
    }

    public function getCurrentSubscription(int $tenantId): ?TenantSubscription
    {
        $subs = $this->getSubscriptionsForTenant($tenantId);
        foreach ($subs as $sub) {
            if ($sub->status === TenantSubscription::STATUS_ACTIVE || $sub->status === TenantSubscription::STATUS_TRIAL) {
                return $sub;
            }
        }

        return $subs[0] ?? null;
    }

    /**
     * @return array<int, TenantSubscription>
     */
    public function getSubscriptionsForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tenant_subscriptions WHERE tenant_id = :tenant_id ORDER BY id DESC'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[] = TenantSubscription::fromArray($row);
        }

        return $result;
    }

    /**
     * @return array<int, TenantUsage>
     */
    public function getUsageLogsForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tenant_usages WHERE tenant_id = :tenant_id ORDER BY id DESC LIMIT 100'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $logs = [];
        foreach ($rows as $row) {
            $logs[] = TenantUsage::fromArray($row);
        }

        return $logs;
    }

    private function findPendingSubscription(int $subscriptionOrTenantId): ?TenantSubscription
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tenant_subscriptions WHERE id = :id AND status = :status LIMIT 1'
        );
        $stmt->execute([
            'id' => $subscriptionOrTenantId,
            'status' => TenantSubscription::STATUS_PENDING_PAYMENT,
        ]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return TenantSubscription::fromArray($row);
        }

        $stmtByTenant = $this->pdo->prepare(
            'SELECT * FROM tenant_subscriptions WHERE tenant_id = :tenant_id AND status = :status ORDER BY id DESC LIMIT 1'
        );
        $stmtByTenant->execute([
            'tenant_id' => $subscriptionOrTenantId,
            'status' => TenantSubscription::STATUS_PENDING_PAYMENT,
        ]);
        /** @var array<string, mixed>|false $rowByTenant */
        $rowByTenant = $stmtByTenant->fetch(PDO::FETCH_ASSOC);
        if ($rowByTenant !== false) {
            return TenantSubscription::fromArray($rowByTenant);
        }

        return null;
    }

    private function markSubscriptionExpired(int $subscriptionId, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tenant_subscriptions SET status = :status, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'status' => TenantSubscription::STATUS_EXPIRED,
            'updated_at' => $now->format('Y-m-d H:i:s'),
            'id' => $subscriptionId,
        ]);
    }

    private function generateUniqueSlug(string $source): string
    {
        $transliterated = strtr($source, [
            'Ç' => 'c', 'ç' => 'c',
            'Ğ' => 'g', 'ğ' => 'g',
            'İ' => 'i', 'I' => 'i', 'ı' => 'i',
            'Ö' => 'o', 'ö' => 'o',
            'Ş' => 's', 'ş' => 's',
            'Ü' => 'u', 'ü' => 'u',
        ]);
        $lower = strtolower($transliterated);
        $cleaned = preg_replace('/[^a-z0-9]+/', '-', $lower);
        $baseSlug = trim((string)$cleaned, '-');
        if ($baseSlug === '') {
            $baseSlug = 'firma';
        }

        $candidate = $baseSlug;
        $counter = 2;
        while ($this->getTenantBySlug($candidate) !== null) {
            $candidate = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     */
    private function extractString(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $val = trim((string)$payload[$key]);
                if ($val !== '') {
                    return $val;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     */
    private function extractOptionalString(array $payload, array $keys): ?string
    {
        $val = $this->extractString($payload, $keys);
        return $val !== '' ? $val : null;
    }
}
