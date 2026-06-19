<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

class Database
{
    private ?PDO $pdo = null;

    /**
     * @param array{host: string, dbname: string, username: string, password: string, enabled: bool} $config
     */
    public function __construct(private readonly array $config)
    {
        if ($this->config['enabled']) {
            $this->connect();
        }
    }

    private function connect(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $this->config['host'],
            $this->config['dbname']
        );

        try {
            $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // Log or handle error, but keep pdo null to allow graceful fallback
            $this->pdo = null;
        }
    }

    public function getConnection(): ?PDO
    {
        return $this->pdo;
    }

    public function isEnabled(): bool
    {
        return $this->config['enabled'] && $this->pdo !== null;
    }

    public function initSchema(): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        $sql = "CREATE TABLE IF NOT EXISTS reconciliations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            store_name VARCHAR(255) NOT NULL,
            terminal_count INT NOT NULL,
            store_count INT NOT NULL,
            matched_count INT NOT NULL,
            missing_count INT NOT NULL,
            extra_count INT NOT NULL,
            results_json LONGTEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        try {
            $this->pdo->exec($sql);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Saves a reconciliation result.
     *
     * @param string $storeName
     * @param ReconciliationResult $result
     * @return int|null
     */
    public function save(string $storeName, ReconciliationResult $result): ?int
    {
        if ($this->pdo === null) {
            return null;
        }

        $sql = "INSERT INTO reconciliations 
                (store_name, terminal_count, store_count, matched_count, missing_count, extra_count, results_json)
                VALUES (:store_name, :terminal_count, :store_count, :matched_count, :missing_count, :extra_count, :results_json)";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'store_name' => $storeName,
                'terminal_count' => count($result->terminalBarcodes),
                'store_count' => count($result->storeBarcodes),
                'matched_count' => count($result->matched),
                'missing_count' => count($result->missingInStore),
                'extra_count' => count($result->extraInStore),
                'results_json' => json_encode($result->toArray(), JSON_UNESCAPED_UNICODE),
            ]);

            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Retrieves all reconciliations.
     *
     * @return array<array{
     *     id: int,
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
    public function getAll(): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $sql = "SELECT id, store_name, terminal_count, store_count, matched_count, missing_count, extra_count, results_json, created_at 
                FROM reconciliations 
                ORDER BY created_at DESC";

        try {
            $stmt = $this->pdo->query($sql);
            if ($stmt === false) {
                return [];
            }
            /** @var array<array{
             *     id: int|string,
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
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Retrieves a single reconciliation by ID.
     *
     * @param int $id
     * @return array{
     *     id: int,
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
    public function getById(int $id): ?array
    {
        if ($this->pdo === null) {
            return null;
        }

        $sql = "SELECT id, store_name, terminal_count, store_count, matched_count, missing_count, extra_count, results_json, created_at 
                FROM reconciliations 
                WHERE id = :id";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            /** @var array{
             *     id: int|string,
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
                'store_name' => $row['store_name'],
                'terminal_count' => (int)$row['terminal_count'],
                'store_count' => (int)$row['store_count'],
                'matched_count' => (int)$row['matched_count'],
                'missing_count' => (int)$row['missing_count'],
                'extra_count' => (int)$row['extra_count'],
                'results_json' => $row['results_json'],
                'created_at' => $row['created_at'],
            ];
        } catch (PDOException $e) {
            return null;
        }
    }
}
