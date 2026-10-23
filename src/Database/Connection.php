<?php

declare(strict_types=1);

namespace Docvera\Database;

use Docvera\Core\Config;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Unified database connection supporting PDO (mysql, sqlite) and an atomic flock-backed JSON table store (file).
 */
final class Connection
{
    private string $driver;
    private ?PDO $pdo = null;
    private string $fileStorePath;

    public function __construct(Config $config)
    {
        $this->driver = strtolower((string) $config->get('database.connection', $config->get('database.driver', 'file')));
        $this->fileStorePath = (string) $config->get('database.file_store_path');

        if ($this->driver === 'mysql' || $this->driver === 'pgsql') {
            $customDsn = trim((string) $config->get('database.dsn', ''));
            $host = (string) $config->get('database.host', '127.0.0.1');
            $port = (int) $config->get('database.port', 3306);
            $db = (string) $config->get('database.database', 'docvera');
            $user = (string) $config->get('database.username', 'root');
            $pass = (string) $config->get('database.password', '');

            $dsn = $customDsn !== ''
                ? $customDsn
                : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db);
            $this->pdo = new PDO($dsn, $user !== '' ? $user : null, $pass !== '' ? $pass : null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } elseif ($this->driver === 'sqlite') {
            $sqlitePath = (string) $config->get('database.sqlite_path');
            $dir = dirname($sqlitePath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            $this->pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } else {
            $this->driver = 'file';
            $dir = dirname($this->fileStorePath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            if (!is_file($this->fileStorePath)) {
                $this->writeFileStore($this->defaultFileStoreStructure());
            }
        }
    }

    public function getDriverName(): string
    {
        return $this->driver;
    }

    public function isPdo(): bool
    {
        return $this->pdo !== null;
    }

    public function getPdo(): ?PDO
    {
        return $this->pdo;
    }

    public function isHealthy(): bool
    {
        try {
            if ($this->pdo !== null) {
                $this->pdo->query('SELECT 1');
                return true;
            }
            $dir = dirname($this->fileStorePath);
            return is_dir($dir) && is_writable($dir);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param callable(array<string, array<string, array<string, mixed>>>): array<string, array<string, array<string, mixed>>> $mutator
     */
    public function mutateFileStore(callable $mutator): void
    {
        $dir = dirname($this->fileStorePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $fp = fopen($this->fileStorePath, 'c+');
        if ($fp === false) {
            throw new RuntimeException('Unable to open local database store.');
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire lock on local database store.');
            }
            $contents = stream_get_contents($fp);
            $data = ($contents !== false && trim($contents) !== '')
                ? json_decode($contents, true)
                : null;

            if (!is_array($data)) {
                $data = $this->defaultFileStoreStructure();
            }

            $updated = $mutator($data);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function readFileStore(): array
    {
        if (!is_file($this->fileStorePath)) {
            return $this->defaultFileStoreStructure();
        }
        $raw = @file_get_contents($this->fileStorePath);
        if ($raw === false || trim($raw) === '') {
            return $this->defaultFileStoreStructure();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $this->defaultFileStoreStructure();
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $data
     */
    private function writeFileStore(array $data): void
    {
        @file_put_contents(
            $this->fileStorePath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function defaultFileStoreStructure(): array
    {
        return [
            'migrations' => [],
            'processing_jobs' => [],
            'system_settings' => [],
            'user_quotas' => [],
            'users' => [],
            'plans' => [],
            'subscriptions' => [],
            'payments' => [],
            'webhook_events' => [],
            'usage_buckets' => [],
            'api_keys' => [],
            'api_audit_logs' => [],
            'tool_overrides' => [],
            'cms_pages' => [],
        ];
    }
}

