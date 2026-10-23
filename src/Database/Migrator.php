<?php

declare(strict_types=1);

namespace Docvera\Database;

use PDOException;

final class Migrator
{
    private Connection $connection;
    private string $migrationsPath;

    public function __construct(Connection $connection, string $migrationsPath)
    {
        $this->connection = $connection;
        $this->migrationsPath = rtrim($migrationsPath, '/');
    }

    /**
     * Runs all pending migrations idempotently and returns executed migration IDs.
     *
     * @return list<string>
     */
    public function migrate(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '/*.sql') ?: [];
        sort($files);

        $executed = [];

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return [];
            }

            $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id VARCHAR(128) PRIMARY KEY, executed_at VARCHAR(32) NOT NULL)');
            $existingRows = $pdo->query('SELECT id FROM migrations')->fetchAll();
            $alreadyRun = array_column($existingRows, 'id');

            foreach ($files as $file) {
                $id = basename($file);
                if (in_array($id, $alreadyRun, true)) {
                    continue;
                }
                $sql = (string) file_get_contents($file);
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($statements as $statement) {
                    if ($statement === '') {
                        continue;
                    }
                    try {
                        $pdo->exec($statement);
                    } catch (PDOException $e) {
                        // Allow idempotent ALTER TABLE ADD COLUMN if column already exists
                        if (stripos($statement, 'ALTER TABLE') !== false && stripos($e->getMessage(), 'duplicate column') !== false) {
                            continue;
                        }
                        throw $e;
                    }
                }
                $stmt = $pdo->prepare('INSERT INTO migrations (id, executed_at) VALUES (:id, :executed_at)');
                $stmt->execute([
                    'id' => $id,
                    'executed_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
                $executed[] = $id;
            }

            return $executed;
        }

        // File store migration tracking
        $tables = [
            'migrations',
            'processing_jobs',
            'system_settings',
            'user_quotas',
            'users',
            'plans',
            'subscriptions',
            'payments',
            'webhook_events',
            'usage_buckets',
            'api_keys',
            'api_audit_logs',
            'tool_overrides',
            'cms_pages',
        ];

        $this->connection->mutateFileStore(function (array $store) use ($files, $tables, &$executed): array {
            foreach ($tables as $table) {
                if (!isset($store[$table]) || !is_array($store[$table])) {
                    $store[$table] = [];
                }
            }
            foreach ($files as $file) {
                $id = basename($file);
                if (!isset($store['migrations'][$id])) {
                    $store['migrations'][$id] = [
                        'id' => $id,
                        'executed_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    ];
                    $executed[] = $id;
                }
            }
            return $store;
        });

        return $executed;
    }

    /**
     * @return list<string>
     */
    public function getAppliedMigrations(): array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return [];
            }
            $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id VARCHAR(128) PRIMARY KEY, executed_at VARCHAR(32) NOT NULL)');
            $rows = $pdo->query('SELECT id FROM migrations ORDER BY id ASC')->fetchAll();
            return array_values(array_map(static fn(array $r): string => (string) $r['id'], $rows));
        }

        $store = $this->connection->readFileStore();
        return array_keys($store['migrations'] ?? []);
    }

    /**
     * @return list<string>
     */
    public function getPendingMigrations(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }
        $files = glob($this->migrationsPath . '/*.sql') ?: [];
        sort($files);
        $applied = $this->getAppliedMigrations();
        $pending = [];
        foreach ($files as $file) {
            $id = basename($file);
            if (!in_array($id, $applied, true)) {
                $pending[] = $id;
            }
        }
        return $pending;
    }
}
