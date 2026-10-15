-- GP-BIZ-19: HBC Mutabakat Multi-Tenant SaaS Üyelik, Kota ve Abonelik Şeması
-- Tarih: 2026-10-15

CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(255) NOT NULL,
    slug VARCHAR(150) NOT NULL UNIQUE,
    tax_number VARCHAR(50) NULL,
    contact_name VARCHAR(191) NOT NULL,
    contact_email VARCHAR(191) NOT NULL,
    contact_phone VARCHAR(50) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'trial' COMMENT 'trial, active, pending_payment, suspended',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenants_status (status),
    INDEX idx_tenants_email (contact_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    plan_code VARCHAR(64) NOT NULL COMMENT 'trial, supplier_starter, logistics_pro, enterprise_unlimited',
    billing_cycle VARCHAR(16) NOT NULL DEFAULT 'monthly' COMMENT 'monthly, yearly',
    price_try DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    monthly_ocr_quota INT NOT NULL DEFAULT 50 COMMENT 'Aylık koli/irsaliye OCR okuma kotası (-1: sınırsız)',
    used_ocr_count INT NOT NULL DEFAULT 0,
    trial_ends_at DATETIME NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'trial' COMMENT 'trial, pending_payment, active, expired, cancelled',
    payment_reference VARCHAR(191) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_subs_tenant_status (tenant_id, status),
    INDEX idx_tenant_subs_plan (plan_code),
    CONSTRAINT fk_tenant_subscriptions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_usages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    subscription_id INT NOT NULL,
    reconciliation_id INT NULL,
    store_name VARCHAR(255) NOT NULL,
    ocr_units INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_usages_tenant (tenant_id, created_at),
    INDEX idx_tenant_usages_sub (subscription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reconciliations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL DEFAULT 1,
    company_id VARCHAR(64) NOT NULL DEFAULT '1',
    store_name VARCHAR(255) NOT NULL,
    terminal_count INT NOT NULL,
    store_count INT NOT NULL,
    matched_count INT NOT NULL,
    missing_count INT NOT NULL,
    extra_count INT NOT NULL,
    results_json LONGTEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reconciliations_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
