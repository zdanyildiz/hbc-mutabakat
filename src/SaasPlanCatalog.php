<?php

declare(strict_types=1);

namespace App;

/**
 * 2026 Reel Türkiye SaaS Paket Matrisi ve Ücretsiz Demo Kancası (GP-BIZ-19).
 */
class SaasPlanCatalog
{
    public const PLAN_TRIAL = 'trial';
    public const PLAN_SUPPLIER_STARTER = 'supplier_starter';
    public const PLAN_LOGISTICS_PRO = 'logistics_pro';
    public const PLAN_ENTERPRISE_UNLIMITED = 'enterprise_unlimited';

    public const CYCLE_MONTHLY = 'monthly';
    public const CYCLE_YEARLY = 'yearly';

    public const TRIAL_DAYS = 90;
    public const TRIAL_OCR_QUOTA = 50;
    public const UNLIMITED_QUOTA = -1;

    /**
     * Returns all SaaS plans including the 90-day / 50 OCR free trial and paid tiers.
     *
     * @return array<string, array{
     *     plan_code: string,
     *     name: string,
     *     description: string,
     *     monthly_price_try: float,
     *     yearly_price_try: float,
     *     monthly_ocr_quota: int,
     *     trial_days: int,
     *     is_trial: bool,
     *     is_unlimited: bool,
     *     excel_erp_export: bool,
     *     custom_erp_integration: bool,
     *     badge: string,
     *     features: array<int, string>
     * }>
     */
    public static function getAllPlans(): array
    {
        return [
            self::PLAN_TRIAL => [
                'plan_code' => self::PLAN_TRIAL,
                'name' => 'Canlı Demo / Deneme',
                'description' => '90 Gün / 50 Adet Koli & İrsaliye OCR Okuma Ücretsiz Deneme.',
                'monthly_price_try' => 0.0,
                'yearly_price_try' => 0.0,
                'monthly_ocr_quota' => self::TRIAL_OCR_QUOTA,
                'trial_days' => self::TRIAL_DAYS,
                'is_trial' => true,
                'is_unlimited' => false,
                'excel_erp_export' => true,
                'custom_erp_integration' => false,
                'badge' => '90 Gün Ücretsiz',
                'features' => [
                    '90 Gün Ücretsiz Tam Fonksiyonlu Deneme',
                    '50 Adet Koli & İrsaliye OCR Okuma Hakkı',
                    'Deterministik Barkod & İrsaliye Eşleştirme',
                    'Bölünmüş Satır ve Glif Hata Kurtarma',
                ],
            ],
            self::PLAN_SUPPLIER_STARTER => [
                'plan_code' => self::PLAN_SUPPLIER_STARTER,
                'name' => 'Tedarikçi Başlangıç Paketi',
                'description' => 'Aylık 500 OCR Koli/İrsaliye Mutabakatı — Tedarikçi ve sevkiyat depoları için başlangıç paketi.',
                'monthly_price_try' => 2490.0,
                'yearly_price_try' => 24900.0,
                'monthly_ocr_quota' => 500,
                'trial_days' => 0,
                'is_trial' => false,
                'is_unlimited' => false,
                'excel_erp_export' => true,
                'custom_erp_integration' => false,
                'badge' => 'Ekonomik Başlangıç',
                'features' => [
                    'Aylık 500 OCR Koli/İrsaliye Mutabakatı',
                    'El Terminali (Excel/CSV) & Mağaza PDF Karşılaştırma',
                    'Bölünmüş Satır ve Glif Hata Kurtarma',
                    'Temel Mutabakat Raporlama ve Geçmiş Arşivi',
                ],
            ],
            self::PLAN_LOGISTICS_PRO => [
                'plan_code' => self::PLAN_LOGISTICS_PRO,
                'name' => 'Lojistik & Perakende Pro',
                'description' => 'Aylık 2.500 OCR Koli/İrsaliye Mutabakatı + Excel/ERP Çıktısı (39.900 ₺ Yıllık Lisans paketiyle tam uyumlu).',
                'monthly_price_try' => 4990.0,
                'yearly_price_try' => 39900.0,
                'monthly_ocr_quota' => 2500,
                'trial_days' => 0,
                'is_trial' => false,
                'is_unlimited' => false,
                'excel_erp_export' => true,
                'custom_erp_integration' => false,
                'badge' => 'En Çok Tercih Edilen',
                'features' => [
                    'Aylık 2.500 OCR Koli/İrsaliye Mutabakatı',
                    'Gelişmiş Excel & ERP Uyuşmazlık Çıktısı',
                    'Çoklu Mağaza PDF Toplu İşleme',
                    'Yıllık Peşin Lisans: 39.900 ₺/Yıl (Ücretsiz Kurulum)',
                ],
            ],
            self::PLAN_ENTERPRISE_UNLIMITED => [
                'plan_code' => self::PLAN_ENTERPRISE_UNLIMITED,
                'name' => 'Kurumsal Sınırsız / Özel ERP Paketi',
                'description' => 'Sınırsız OCR + Özel Depo/ERP Entegrasyonu — Büyük lojistik filoları ve perakende zincirleri için.',
                'monthly_price_try' => 9900.0,
                'yearly_price_try' => 89900.0,
                'monthly_ocr_quota' => self::UNLIMITED_QUOTA,
                'trial_days' => 0,
                'is_trial' => false,
                'is_unlimited' => true,
                'excel_erp_export' => true,
                'custom_erp_integration' => true,
                'badge' => 'Sınırsız & Özel ERP',
                'features' => [
                    'Sınırsız Koli & İrsaliye OCR Okuma',
                    'Özel Depo / WMS / ERP Entegrasyonu',
                    'Firma Bazlı Özel Barkod ve Ön Ek Kuralları',
                    '7/24 Öncelikli Destek ve Dedicated OCR Worker',
                ],
            ],
        ];
    }

    /**
     * Returns only paid upgradeable plans.
     *
     * @return array<string, array{
     *     plan_code: string,
     *     name: string,
     *     description: string,
     *     monthly_price_try: float,
     *     yearly_price_try: float,
     *     monthly_ocr_quota: int,
     *     trial_days: int,
     *     is_trial: bool,
     *     is_unlimited: bool,
     *     excel_erp_export: bool,
     *     custom_erp_integration: bool,
     *     badge: string,
     *     features: array<int, string>
     * }>
     */
    public static function getPaidPlans(): array
    {
        $plans = self::getAllPlans();
        unset($plans[self::PLAN_TRIAL]);
        return $plans;
    }

    /**
     * Retrieves a single plan definition by plan code (fail-closed on unknown code).
     *
     * @param string $planCode
     * @return array{
     *     plan_code: string,
     *     name: string,
     *     description: string,
     *     monthly_price_try: float,
     *     yearly_price_try: float,
     *     monthly_ocr_quota: int,
     *     trial_days: int,
     *     is_trial: bool,
     *     is_unlimited: bool,
     *     excel_erp_export: bool,
     *     custom_erp_integration: bool,
     *     badge: string,
     *     features: array<int, string>
     * }
     * @throws \InvalidArgumentException
     */
    public static function getPlan(string $planCode): array
    {
        $plans = self::getAllPlans();
        if (!isset($plans[$planCode])) {
            throw new \InvalidArgumentException("Geçersiz SaaS paket kodu: {$planCode}");
        }

        return $plans[$planCode];
    }

    /**
     * Resolves price in TRY for a given plan code and billing cycle.
     *
     * @param string $planCode
     * @param string $billingCycle
     * @return float
     * @throws \InvalidArgumentException
     */
    public static function resolvePriceTry(string $planCode, string $billingCycle = self::CYCLE_MONTHLY): float
    {
        $plan = self::getPlan($planCode);

        if ($billingCycle === self::CYCLE_MONTHLY) {
            return $plan['monthly_price_try'];
        }

        if ($billingCycle === self::CYCLE_YEARLY) {
            return $plan['yearly_price_try'];
        }

        throw new \InvalidArgumentException("Geçersiz faturalama dönemi: {$billingCycle}");
    }

    /**
     * Resolves monthly OCR quota for a given plan code (-1 for unlimited).
     *
     * @param string $planCode
     * @return int
     * @throws \InvalidArgumentException
     */
    public static function resolveMonthlyQuota(string $planCode): int
    {
        $plan = self::getPlan($planCode);
        return $plan['monthly_ocr_quota'];
    }
}
