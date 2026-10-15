<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

class Database
{
    private ?PDO $pdo = null;
    private ?PDO $saasFallbackPdo = null;

    /**
     * @param array{
     *     host?: string,
     *     dbname?: string,
     *     username?: string,
     *     password?: string,
     *     enabled?: bool,
     *     dsn?: string,
     *     sqlite_path?: string
     * } $config
     */
    public function __construct(
        private readonly array $config = [],
        ?PDO $pdo = null
    ) {
        if ($pdo !== null) {
            $this->pdo = $pdo;
            return;
        }

        if (($this->config['enabled'] ?? false) === true) {
            $this->connect();
        }
    }

    private function connect(): void
    {
        $dsn = isset($this->config['dsn']) && $this->config['dsn'] !== ''
            ? $this->config['dsn']
            : sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                $this->config['host'] ?? 'localhost',
                $this->config['dbname'] ?? 'hbc_mutabakat'
            );

        try {
            if (str_starts_with($dsn, 'sqlite:')) {
                $this->pdo = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            } else {
                $this->pdo = new PDO(
                    $dsn,
                    $this->config['username'] ?? 'root',
                    $this->config['password'] ?? '',
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
            }
        } catch (PDOException) {
            // Keep pdo null to allow graceful fallback
            $this->pdo = null;
        }
    }

    public function getConnection(): ?PDO
    {
        return $this->pdo;
    }

    /**
     * Returns the active PDO connection for SaaS operations, falling back to local SQLite if MySQL is disabled.
     */
    public function getSaasConnection(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if ($this->saasFallbackPdo !== null) {
            return $this->saasFallbackPdo;
        }

        $sqlitePath = $this->config['sqlite_path'] ?? (dirname(__DIR__) . '/var/saas_tenants.sqlite');
        $dir = dirname($sqlitePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        try {
            $this->saasFallbackPdo = new PDO('sqlite:' . $sqlitePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException) {
            $this->saasFallbackPdo = new PDO('sqlite::memory:', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        $this->initSchema($this->saasFallbackPdo);

        return $this->saasFallbackPdo;
    }

    public function isEnabled(): bool
    {
        return $this->pdo !== null && (($this->config['enabled'] ?? true) === true);
    }

    public function initSchema(?PDO $targetPdo = null): bool
    {
        $pdo = $targetPdo ?? $this->pdo;
        if ($pdo === null) {
            return false;
        }

        $driverAttr = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = is_string($driverAttr) ? $driverAttr : 'mysql';
        $isSqlite = ($driver === 'sqlite');

        $autoIncPk = $isSqlite
            ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
            : 'INT AUTO_INCREMENT PRIMARY KEY';
        $tableSuffix = $isSqlite
            ? ''
            : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $longTextType = $isSqlite ? 'TEXT' : 'LONGTEXT';

        $queries = [
            "CREATE TABLE IF NOT EXISTS tenants (
                id {$autoIncPk},
                company_name VARCHAR(255) NOT NULL,
                slug VARCHAR(150) NOT NULL UNIQUE,
                tax_number VARCHAR(50) NULL,
                contact_name VARCHAR(191) NOT NULL,
                contact_email VARCHAR(191) NOT NULL,
                contact_phone VARCHAR(50) NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'trial',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ){$tableSuffix};",

            "CREATE TABLE IF NOT EXISTS tenant_subscriptions (
                id {$autoIncPk},
                tenant_id INT NOT NULL,
                plan_code VARCHAR(64) NOT NULL,
                billing_cycle VARCHAR(16) NOT NULL DEFAULT 'monthly',
                price_try DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                monthly_ocr_quota INT NOT NULL DEFAULT 50,
                used_ocr_count INT NOT NULL DEFAULT 0,
                trial_ends_at DATETIME NULL,
                starts_at DATETIME NOT NULL,
                ends_at DATETIME NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'trial',
                payment_reference VARCHAR(191) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ){$tableSuffix};",

            "CREATE TABLE IF NOT EXISTS tenant_usages (
                id {$autoIncPk},
                tenant_id INT NOT NULL,
                subscription_id INT NOT NULL,
                reconciliation_id INT NULL,
                store_name VARCHAR(255) NOT NULL,
                ocr_units INT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ){$tableSuffix};",

            "CREATE TABLE IF NOT EXISTS reconciliations (
                id {$autoIncPk},
                tenant_id INT NOT NULL DEFAULT 1,
                company_id VARCHAR(64) NOT NULL DEFAULT '1',
                store_name VARCHAR(255) NOT NULL,
                terminal_count INT NOT NULL,
                store_count INT NOT NULL,
                matched_count INT NOT NULL,
                missing_count INT NOT NULL,
                extra_count INT NOT NULL,
                results_json {$longTextType} NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ){$tableSuffix};",
        ];

        try {
            foreach ($queries as $sql) {
                $pdo->exec($sql);
            }
            $this->ensureReconciliationsTenantColumns($pdo);
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function ensureReconciliationsTenantColumns(PDO $pdo): void
    {
        try {
            $pdo->query('SELECT tenant_id FROM reconciliations LIMIT 1');
        } catch (PDOException) {
            try {
                $pdo->exec('ALTER TABLE reconciliations ADD COLUMN tenant_id INT NOT NULL DEFAULT 1');
            } catch (PDOException) {
                // Ignore if already added concurrently
            }
        }

        try {
            $pdo->query('SELECT company_id FROM reconciliations LIMIT 1');
        } catch (PDOException) {
            try {
                $pdo->exec("ALTER TABLE reconciliations ADD COLUMN company_id VARCHAR(64) NOT NULL DEFAULT '1'");
            } catch (PDOException) {
                // Ignore if already added concurrently
            }
        }
    }

    /**
     * Saves a reconciliation result isolated by tenant_id.
     *
     * @param string $storeName
     * @param ReconciliationResult $result
     * @param string|int $companyId
     * @param int|null $tenantId
     * @return int|null
     */
    public function save(
        string $storeName,
        ReconciliationResult $result,
        string|int $companyId = '1',
        ?int $tenantId = null
    ): ?int {
        if ($this->pdo === null) {
            return null;
        }

        $effectiveTenantId = $tenantId ?? TenantContext::getTenantId() ?? 1;

        $sql = 'INSERT INTO reconciliations 
                (tenant_id, company_id, store_name, terminal_count, store_count, matched_count, missing_count, extra_count, results_json)
                VALUES (:tenant_id, :company_id, :store_name, :terminal_count, :store_count, :matched_count, :missing_count, :extra_count, :results_json)';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'tenant_id' => $effectiveTenantId,
                'company_id' => (string)$companyId,
                'store_name' => $storeName,
                'terminal_count' => count($result->terminalBarcodes),
                'store_count' => count($result->storeBarcodes),
                'matched_count' => count($result->matched),
                'missing_count' => count($result->missingInStore),
                'extra_count' => count($result->extraInStore),
                'results_json' => (string)json_encode($result->toArray(), JSON_UNESCAPED_UNICODE),
            ]);

            return (int)$this->pdo->lastInsertId();
        } catch (PDOException) {
            return null;
        }
    }

    /**
     * Retrieves all reconciliations for the active or specified tenant.
     *
     * @param int|null $tenantId
     * @return array<array{
     *     id: int,
     *     tenant_id: int,
     *     store_name: string,
     *     terminal_count: int,
     *     store_count: int,
     *     matched_count: int,
     *     missing_count: int,
     *     extra_count: int,
     *     results_json: string,
     *     created_at: string
     * }>
     */
    public function getAll(?int $tenantId = null): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $effectiveTenantId = $tenantId ?? TenantContext::getTenantId() ?? 1;

        $sql = 'SELECT id, tenant_id, store_name, terminal_count, store_count, matched_count, missing_count, extra_count, results_json, created_at 
                FROM reconciliations 
                WHERE tenant_id = :tenant_id
                ORDER BY created_at DESC, id DESC 
                LIMIT 50';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['tenant_id' => $effectiveTenantId]);
            /** @var array<array{
             *     id: int|string,
             *     tenant_id?: int|string,
             *     store_name: string,
             *     terminal_count: int|string,
             *     store_count: int|string,
             *     matched_count: int|string,
             *     missing_count: int|string,
             *     extra_count: int|string,
             *     results_json: string,
             *     created_at: string
             * }> $rows */
            $rows = $stmt->fetchAll();
            $results = [];
            foreach ($rows as $row) {
                $results[] = [
                    'id' => (int)$row['id'],
                    'tenant_id' => isset($row['tenant_id']) ? (int)$row['tenant_id'] : $effectiveTenantId,
                    'store_name' => $row['store_name'],
                    'terminal_count' => (int)$row['terminal_count'],
                    'store_count' => (int)$row['store_count'],
                    'matched_count' => (int)$row['matched_count'],
                    'missing_count' => (int)$row['missing_count'],
                    'extra_count' => (int)$row['extra_count'],
                    'results_json' => $row['results_json'],
                    'created_at' => $row['created_at'],
                ];
            }
            return $results;
        } catch (PDOException) {
            return [];
        }
    }

    /**
     * Retrieves a single reconciliation by ID, strictly isolated by tenant_id.
     *
     * @param int $id
     * @param int|null $tenantId
     * @return array{
     *     id: int,
     *     tenant_id: int,
     *     store_name: string,
     *     terminal_count: int,
     *     store_count: int,
     *     matched_count: int,
     *     missing_count: int,
     *     extra_count: int,
     *     results_json: string,
     *     created_at: string
     * }|null
     */
    public function getById(int $id, ?int $tenantId = null): ?array
    {
        if ($this->pdo === null) {
            return null;
        }

        $effectiveTenantId = $tenantId ?? TenantContext::getTenantId() ?? 1;

        $sql = 'SELECT id, tenant_id, store_name, terminal_count, store_count, matched_count, missing_count, extra_count, results_json, created_at 
                FROM reconciliations 
                WHERE id = :id AND tenant_id = :tenant_id';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'id' => $id,
                'tenant_id' => $effectiveTenantId,
            ]);
            /** @var array{
             *     id: int|string,
             *     tenant_id?: int|string,
             *     store_name: string,
             *     terminal_count: int|string,
             *     store_count: int|string,
             *     matched_count: int|string,
             *     missing_count: int|string,
             *     extra_count: int|string,
             *     results_json: string,
             *     created_at: string
             * }|false $row */
            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }
            return [
                'id' => (int)$row['id'],
                'tenant_id' => isset($row['tenant_id']) ? (int)$row['tenant_id'] : $effectiveTenantId,
                'store_name' => $row['store_name'],
                'terminal_count' => (int)$row['terminal_count'],
                'store_count' => (int)$row['store_count'],
                'matched_count' => (int)$row['matched_count'],
                'missing_count' => (int)$row['missing_count'],
                'extra_count' => (int)$row['extra_count'],
                'results_json' => $row['results_json'],
                'created_at' => $row['created_at'],
            ];
        } catch (PDOException) {
            return null;
        }
    }
}
