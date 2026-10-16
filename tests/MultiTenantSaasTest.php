<?php

declare(strict_types=1);

namespace Tests;

use App\Database;
use App\ExcelExtractor;
use App\PdfExtractor;
use App\Reconciler;
use App\ReconciliationResult;
use App\SaasPlanCatalog;
use App\SaasQuotaException;
use App\SubscriptionService;
use App\Tenant;
use App\TenantContext;
use App\TenantMiddleware;
use App\TenantSubscription;
use PDO;
use PHPUnit\Framework\TestCase;

class MultiTenantSaasTest extends TestCase
{
    private PDO $pdo;
    private Database $db;
    private SubscriptionService $subscriptionService;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();

        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->db = new Database(['enabled' => true], $this->pdo);
        $this->assertTrue($this->db->initSchema());

        $this->subscriptionService = new SubscriptionService($this->db, 'test_hmac_secret_2026');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function testPlanCatalogMatches2026PricingMatrixAndTrialHook(): void
    {
        $plans = SaasPlanCatalog::getAllPlans();

        $this->assertArrayHasKey(SaasPlanCatalog::PLAN_TRIAL, $plans);
        $this->assertArrayHasKey(SaasPlanCatalog::PLAN_SUPPLIER_STARTER, $plans);
        $this->assertArrayHasKey(SaasPlanCatalog::PLAN_LOGISTICS_PRO, $plans);
        $this->assertArrayHasKey(SaasPlanCatalog::PLAN_ENTERPRISE_UNLIMITED, $plans);

        // 1. Canlı Demo / Deneme: 90 Gün / 50 OCR
        $trial = SaasPlanCatalog::getPlan(SaasPlanCatalog::PLAN_TRIAL);
        $this->assertSame(90, $trial['trial_days']);
        $this->assertSame(50, $trial['monthly_ocr_quota']);
        $this->assertSame(0.0, $trial['monthly_price_try']);

        // 2. Tedarikçi Başlangıç: 500 OCR — 2.490 ₺/ay (Yıllık: 24.900 ₺)
        $starter = SaasPlanCatalog::getPlan(SaasPlanCatalog::PLAN_SUPPLIER_STARTER);
        $this->assertSame(500, $starter['monthly_ocr_quota']);
        $this->assertSame(2490.0, SaasPlanCatalog::resolvePriceTry('supplier_starter', 'monthly'));
        $this->assertSame(24900.0, SaasPlanCatalog::resolvePriceTry('supplier_starter', 'yearly'));

        // 3. Lojistik & Perakende Pro: 2.500 OCR — 4.990 ₺/ay (Yıllık: 39.900 ₺)
        $logisticsPro = SaasPlanCatalog::getPlan(SaasPlanCatalog::PLAN_LOGISTICS_PRO);
        $this->assertSame(2500, $logisticsPro['monthly_ocr_quota']);
        $this->assertSame(4990.0, SaasPlanCatalog::resolvePriceTry('logistics_pro', 'monthly'));
        $this->assertSame(39900.0, SaasPlanCatalog::resolvePriceTry('logistics_pro', 'yearly'));

        // 4. Kurumsal Sınırsız / Özel ERP: Sınırsız OCR — 9.900 ₺/ay (Yıllık: 89.900 ₺)
        $enterprise = SaasPlanCatalog::getPlan(SaasPlanCatalog::PLAN_ENTERPRISE_UNLIMITED);
        $this->assertTrue($enterprise['is_unlimited']);
        $this->assertSame(-1, $enterprise['monthly_ocr_quota']);
        $this->assertSame(9900.0, SaasPlanCatalog::resolvePriceTry('enterprise_unlimited', 'monthly'));
        $this->assertSame(89900.0, SaasPlanCatalog::resolvePriceTry('enterprise_unlimited', 'yearly'));
    }

    public function testRegisterTenantWithTrialStarts90DaysAnd50OcrQuota(): void
    {
        $now = new \DateTimeImmutable('2026-10-15 12:00:00');

        $tenant = $this->subscriptionService->registerTenantWithTrial([
            'company_name' => 'Özyıldız Tekstil ve Lojistik A.Ş.',
            'tax_number' => '6543210987',
            'contact_name' => 'Mehmet Özyıldız',
            'contact_email' => 'mehmet@ozyildizlojistik.com.tr',
            'contact_phone' => '05321112233',
        ], $now);

        $this->assertGreaterThan(0, $tenant->id);
        $this->assertSame('Özyıldız Tekstil ve Lojistik A.Ş.', $tenant->companyName);
        $this->assertSame('ozyildiz-tekstil-ve-lojistik-a-s', $tenant->slug);
        $this->assertSame(Tenant::STATUS_TRIAL, $tenant->status);
        $this->assertSame($tenant->id, TenantContext::getTenantId());

        $sub = $tenant->subscription;
        $this->assertNotNull($sub);
        $this->assertSame(SaasPlanCatalog::PLAN_TRIAL, $sub->planCode);
        $this->assertSame(TenantSubscription::STATUS_TRIAL, $sub->status);
        $this->assertSame(50, $sub->monthlyOcrQuota);
        $this->assertSame(0, $sub->usedOcrCount);
        $this->assertSame(50, $sub->remainingQuota());
        $this->assertSame('2027-01-13 12:00:00', $sub->trialEndsAt);
        $this->assertSame('2027-01-13 12:00:00', $sub->endsAt);
    }

    public function testOcrQuotaConsumptionAndFailClosedEnforcementWhenQuotaExceeded(): void
    {
        $now = new \DateTimeImmutable('2026-10-15 10:00:00');
        $tenant = $this->subscriptionService->registerTenantWithTrial([
            'company_name' => 'Kuzey Kargo Tedarik Ltd.',
            'contact_name' => 'Ayşe Kaya',
            'contact_email' => 'ayse@kuzeykargo.com.tr',
        ], $now);

        // Consume 30 OCR units
        $subAfter30 = $this->subscriptionService->consumeOcrQuota(
            $tenant->id,
            30,
            '34ABC101 - Sevkiyat 1',
            null,
            $now
        );
        $this->assertSame(30, $subAfter30->usedOcrCount);
        $this->assertSame(20, $subAfter30->remainingQuota());

        // Consume remaining 20 OCR units (total 50/50)
        $subAfter50 = $this->subscriptionService->consumeOcrQuota(
            $tenant->id,
            20,
            '34ABC102 - Sevkiyat 2',
            null,
            $now
        );
        $this->assertSame(50, $subAfter50->usedOcrCount);
        $this->assertSame(0, $subAfter50->remainingQuota());

        // Verify usage logs were recorded
        $logs = $this->subscriptionService->getUsageLogsForTenant($tenant->id);
        $this->assertCount(2, $logs);
        $this->assertSame(20, $logs[0]->ocrUnits);
        $this->assertSame(30, $logs[1]->ocrUnits);

        // 51st OCR attempt MUST fail-closed with QUOTA_EXCEEDED and upgradeRequired = true
        try {
            $this->subscriptionService->consumeOcrQuota($tenant->id, 1, '34ABC103 - Fazla', null, $now);
            $this->fail('Kota aşımında SaasQuotaException fırlatılmalıydı.');
        } catch (SaasQuotaException $e) {
            $this->assertSame(SaasQuotaException::CODE_QUOTA_EXCEEDED, $e->getErrorCode());
            $this->assertTrue($e->isUpgradeRequired());
            $this->assertSame($tenant->id, $e->getTenantId());
        }
    }

    public function testExpiredTrialFailsClosedAndMarksSubscriptionExpired(): void
    {
        $registeredAt = new \DateTimeImmutable('2026-10-01 09:00:00');
        $tenant = $this->subscriptionService->registerTenantWithTrial([
            'company_name' => 'Vakit Lojistik A.Ş.',
            'contact_name' => 'Can Demir',
            'contact_email' => 'can@vakitlojistik.com.tr',
        ], $registeredAt);

        // 91 days later (trial expired)
        $afterExpiry = new \DateTimeImmutable('2027-01-01 09:00:01');

        try {
            $this->subscriptionService->assertCanProcessOcr($tenant->id, 1, $afterExpiry);
            $this->fail('Süresi dolmuş deneme aboneliğinde SaasQuotaException fırlatılmalıydı.');
        } catch (SaasQuotaException $e) {
            $this->assertSame(SaasQuotaException::CODE_SUBSCRIPTION_EXPIRED, $e->getErrorCode());
            $this->assertTrue($e->isUpgradeRequired());
        }

        $subInDb = $this->subscriptionService->getSubscriptionById($tenant->subscription->id);
        $this->assertNotNull($subInDb);
        $this->assertSame(TenantSubscription::STATUS_EXPIRED, $subInDb->status);
    }

    public function testTenantDataIsolationPreventsCrossTenantAccess(): void
    {
        $tenantA = $this->subscriptionService->registerTenantWithTrial([
            'company_name' => 'Tenant A Lojistik',
            'contact_name' => 'Ali A',
            'contact_email' => 'ali@tenanta.com.tr',
        ]);

        $tenantB = $this->subscriptionService->registerTenantWithTrial([
            'company_name' => 'Tenant B Perakende',
            'contact_name' => 'Burak B',
            'contact_email' => 'burak@tenantb.com.tr',
        ]);

        // Set context to Tenant A and save reconciliation
        TenantContext::setTenant($tenantA, $tenantA->subscription);
        $resultA = new ReconciliationResult(
            terminalBarcodes: ['150000000000000001', '150000000000000002'],
            storeBarcodes: ['150000000000000001'],
            matched: ['150000000000000001'],
            missingInStore: ['150000000000000002'],
            extraInStore: []
        );
        $reportIdA = $this->db->save('Kadıköy Mağaza - Tenant A', $resultA, '1');
        $this->assertNotNull($reportIdA);

        // Tenant A sees its own report
        $reportsForA = $this->db->getAll();
        $this->assertCount(1, $reportsForA);
        $this->assertSame('Kadıköy Mağaza - Tenant A', $reportsForA[0]['store_name']);
        $this->assertNotNull($this->db->getById($reportIdA));

        // Switch context to Tenant B via TenantMiddleware
        $middleware = new TenantMiddleware($this->subscriptionService);
        $resolvedB = $middleware->resolve(['tenant_id' => $tenantB->id]);
        $this->assertNotNull($resolvedB);
        $this->assertSame($tenantB->id, TenantContext::getTenantId());

        // Tenant B MUST NOT see Tenant A's reports in getAll() or getById()
        $reportsForB = $this->db->getAll();
        $this->assertSame([], $reportsForB);
        $this->assertNull($this->db->getById($reportIdA));

        // Save a report for Tenant B
        $resultB = new ReconciliationResult(
            terminalBarcodes: ['160000000000000099'],
            storeBarcodes: ['160000000000000099'],
            matched: ['160000000000000099'],
            missingInStore: [],
            extraInStore: []
        );
        $reportIdB = $this->db->save('İzmir Depo - Tenant B', $resultB, '1');
        $this->assertNotNull($reportIdB);

        $reportsForBNow = $this->db->getAll();
        $this->assertCount(1, $reportsForBNow);
        $this->assertSame('İzmir Depo - Tenant B', $reportsForBNow[0]['store_name']);

        // Tenant A still cannot access Tenant B's report
        $this->assertNull($this->db->getById($reportIdB, $tenantA->id));
    }

    public function testPendingPaymentToApprovePlanUpgradeFlowWith39900YearlyLogisticsPro(): void
    {
        $now = new \DateTimeImmutable('2026-10-15 14:00:00');
        $tenant = $this->subscriptionService->registerTenantWithTrial([
            'company_name' => 'Anadolu Ekspres Dağıtım A.Ş.',
            'contact_name' => 'Zafer Bey',
            'contact_email' => 'operasyon@anadoluekspres.com.tr',
        ], $now);

        // Consume all 50 trial OCR units
        $this->subscriptionService->consumeOcrQuota($tenant->id, 50, 'Trial-Bitir', null, $now);

        // Request upgrade to Yearly Logistics Pro (39.900 ₺ / Yıl, 2.500 OCR/Ay)
        $pendingSub = $this->subscriptionService->requestPlanUpgrade(
            $tenant->id,
            SaasPlanCatalog::PLAN_LOGISTICS_PRO,
            SaasPlanCatalog::CYCLE_YEARLY,
            'HAVALE-2026-39900',
            $now
        );

        $this->assertSame(TenantSubscription::STATUS_PENDING_PAYMENT, $pendingSub->status);
        $this->assertSame(SaasPlanCatalog::PLAN_LOGISTICS_PRO, $pendingSub->planCode);
        $this->assertSame(SaasPlanCatalog::CYCLE_YEARLY, $pendingSub->billingCycle);
        $this->assertSame(39900.0, $pendingSub->priceTry);
        $this->assertSame(2500, $pendingSub->monthlyOcrQuota);
        $this->assertSame(Tenant::STATUS_PENDING_PAYMENT, $pendingSub->tenant?->status);

        // Crucial check: While in pending_payment, OCR processing MUST be blocked (no unpaid activation!)
        try {
            $this->subscriptionService->assertCanProcessOcr($tenant->id, 1, $now);
            $this->fail('Ödeme onayı bekleyen (pending_payment) abonelik OCR çalıştıramamalıdır.');
        } catch (SaasQuotaException $e) {
            $this->assertSame(SaasQuotaException::CODE_PENDING_PAYMENT, $e->getErrorCode());
            $this->assertTrue($e->isUpgradeRequired());
        }

        // Invalid HMAC signature must be rejected
        try {
            $this->subscriptionService->approvePlanUpgrade($pendingSub->id, 'HAVALE-ONAY', 'invalid_hmac', $now);
            $this->fail('Geçersiz HMAC imzası reddedilmeliydi.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HMAC', $e->getMessage());
        }

        // Approve plan upgrade with valid HMAC signature
        $validHmac = $this->subscriptionService->generateApprovalHmac($pendingSub->id);
        $approvedSub = $this->subscriptionService->approvePlanUpgrade(
            $pendingSub->id,
            'HAVALE-ONAYLANDI-39900',
            $validHmac,
            $now
        );

        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $approvedSub->status);
        $this->assertSame(Tenant::STATUS_ACTIVE, $approvedSub->tenant?->status);
        $this->assertSame(0, $approvedSub->usedOcrCount);
        $this->assertSame(2500, $approvedSub->monthlyOcrQuota);
        $this->assertSame(2500, $approvedSub->remainingQuota());
        $this->assertSame(39900.0, $approvedSub->priceTry);
        $this->assertSame('2027-10-15 14:00:00', $approvedSub->endsAt);

        // Now OCR processing succeeds and consumes from the new 2.500 quota
        $afterUse = $this->subscriptionService->consumeOcrQuota(
            $tenant->id,
            15,
            'Lojistik-Pro-Sevkiyat',
            null,
            $now
        );
        $this->assertSame(15, $afterUse->usedOcrCount);
        $this->assertSame(2485, $afterUse->remainingQuota());
    }

    public function testEnterpriseUnlimitedPlanAndReconcilerQuotaGateIntegration(): void
    {
        $now = new \DateTimeImmutable('2026-10-15 15:00:00');
        $tenant = $this->subscriptionService->registerTenantWithTrial([
            'company_name' => 'Global Perakende Zinciri A.Ş.',
            'contact_name' => 'Selim Yılmaz',
            'contact_email' => 'selim@globalperakende.com.tr',
        ], $now);

        // Exhaust trial quota
        $this->subscriptionService->consumeOcrQuota($tenant->id, 50, 'Trial-Exhaust', null, $now);

        // Reconciler with SubscriptionService attached must fail-closed before reading files when quota is exhausted
        $reconciler = new Reconciler(
            new ExcelExtractor(),
            new PdfExtractor(),
            $this->subscriptionService
        );

        try {
            $reconciler->reconcile('non_existent.xlsx', ['non_existent.pdf'], '1', $tenant->id);
            $this->fail('Kota bitmişken Reconciler fail-closed olarak SaasQuotaException fırlatmalıydı.');
        } catch (SaasQuotaException $e) {
            $this->assertSame(SaasQuotaException::CODE_QUOTA_EXCEEDED, $e->getErrorCode());
        }

        // Upgrade to Enterprise Unlimited (89.900 ₺/Yıl) and approve
        $pendingEnterprise = $this->subscriptionService->requestPlanUpgrade(
            $tenant->id,
            SaasPlanCatalog::PLAN_ENTERPRISE_UNLIMITED,
            SaasPlanCatalog::CYCLE_YEARLY,
            'KURUMSAL-89900',
            $now
        );
        $this->assertSame(89900.0, $pendingEnterprise->priceTry);

        $approvedEnterprise = $this->subscriptionService->approvePlanUpgrade(
            $tenant->id,
            'KURUMSAL-ONAY-89900',
            null,
            $now
        );

        $this->assertTrue($approvedEnterprise->isUnlimited());
        $this->assertNull($approvedEnterprise->remainingQuota());

        // Consume 5,000 OCR units without hitting any limit
        $afterHugeConsumption = $this->subscriptionService->consumeOcrQuota(
            $tenant->id,
            5000,
            'Kurumsal-Toplu-Sevkiyat',
            null,
            $now
        );
        $this->assertSame(5000, $afterHugeConsumption->usedOcrCount);
        $this->assertTrue($afterHugeConsumption->canProcessOcr(10000, $now));
    }
}
