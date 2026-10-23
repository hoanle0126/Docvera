<?php

declare(strict_types=1);

namespace Docvera\Database;

final class JobRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param array<string, mixed> $job
     */
    public function save(array $job): void
    {
        $id = (string) $job['id'];

        $paramsJson = isset($job['request_params']) && is_array($job['request_params'])
            ? json_encode($job['request_params'], JSON_UNESCAPED_SLASHES)
            : ($job['request_params_json'] ?? null);

        $summaryJson = isset($job['summary']) && is_array($job['summary'])
            ? json_encode($job['summary'], JSON_UNESCAPED_SLASHES)
            : ($job['summary_json'] ?? null);

        $record = [
            'id' => $id,
            'tool_id' => (string) ($job['tool_id'] ?? ''),
            'driver_used' => (string) ($job['driver_used'] ?? ''),
            'status' => (string) ($job['status'] ?? 'pending'),
            'session_hash' => (string) ($job['session_hash'] ?? ''),
            'download_token_hash' => (string) ($job['download_token_hash'] ?? ''),
            'user_id' => isset($job['user_id']) && $job['user_id'] !== '' ? (string) $job['user_id'] : null,
            'api_key_id' => isset($job['api_key_id']) && $job['api_key_id'] !== '' ? (string) $job['api_key_id'] : null,
            'source' => (string) ($job['source'] ?? 'web'),
            'execution_mode' => (string) ($job['execution_mode'] ?? 'sync'),
            'page_count' => (int) ($job['page_count'] ?? 0),
            'input_count' => (int) ($job['input_count'] ?? 1),
            'input_bytes' => (int) ($job['input_bytes'] ?? 0),
            'output_filename' => $job['output_filename'] ?? null,
            'output_mime' => $job['output_mime'] ?? null,
            'output_bytes' => (int) ($job['output_bytes'] ?? 0),
            'summary_json' => $summaryJson,
            'request_params_json' => $paramsJson,
            'error_message' => $job['error_message'] ?? null,
            'created_at' => (string) ($job['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
            'completed_at' => $job['completed_at'] ?? null,
            'expires_at' => (string) ($job['expires_at'] ?? gmdate('Y-m-d\TH:i:s\Z', time() + 3600)),
            'deleted_at' => $job['deleted_at'] ?? null,
        ];

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return;
            }
            $existing = $this->find($id);
            if ($existing === null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO processing_jobs (
                        id, tool_id, driver_used, status, session_hash, download_token_hash,
                        user_id, api_key_id, source, execution_mode, page_count,
                        input_count, input_bytes, output_filename, output_mime, output_bytes,
                        summary_json, request_params_json, error_message, created_at, completed_at, expires_at, deleted_at
                    ) VALUES (
                        :id, :tool_id, :driver_used, :status, :session_hash, :download_token_hash,
                        :user_id, :api_key_id, :source, :execution_mode, :page_count,
                        :input_count, :input_bytes, :output_filename, :output_mime, :output_bytes,
                        :summary_json, :request_params_json, :error_message, :created_at, :completed_at, :expires_at, :deleted_at
                    )'
                );
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE processing_jobs SET
                        tool_id = :tool_id,
                        driver_used = :driver_used,
                        status = :status,
                        session_hash = :session_hash,
                        download_token_hash = :download_token_hash,
                        user_id = :user_id,
                        api_key_id = :api_key_id,
                        source = :source,
                        execution_mode = :execution_mode,
                        page_count = :page_count,
                        input_count = :input_count,
                        input_bytes = :input_bytes,
                        output_filename = :output_filename,
                        output_mime = :output_mime,
                        output_bytes = :output_bytes,
                        summary_json = :summary_json,
                        request_params_json = :request_params_json,
                        error_message = :error_message,
                        created_at = :created_at,
                        completed_at = :completed_at,
                        expires_at = :expires_at,
                        deleted_at = :deleted_at
                    WHERE id = :id'
                );
            }

            $stmt->execute($record);
            return;
        }

        $this->connection->mutateFileStore(static function (array $store) use ($id, $record): array {
            $store['processing_jobs'][$id] = $record;
            return $store;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return null;
            }
            $stmt = $pdo->prepare('SELECT * FROM processing_jobs WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                return null;
            }
            return $this->hydrateRow($row);
        }

        $store = $this->connection->readFileStore();
        $row = $store['processing_jobs'][$id] ?? null;
        return is_array($row) ? $this->hydrateRow($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByUserOrSession(?string $userId, ?string $sessionHash, int $limit = 50): array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return [];
            }
            if ($userId !== null && $userId !== '' && $sessionHash !== null && $sessionHash !== '') {
                $stmt = $pdo->prepare(
                    'SELECT * FROM processing_jobs WHERE user_id = :user_id OR session_hash = :session_hash ORDER BY created_at DESC LIMIT ' . max(1, $limit)
                );
                $stmt->execute(['user_id' => $userId, 'session_hash' => $sessionHash]);
            } elseif ($userId !== null && $userId !== '') {
                $stmt = $pdo->prepare(
                    'SELECT * FROM processing_jobs WHERE user_id = :user_id ORDER BY created_at DESC LIMIT ' . max(1, $limit)
                );
                $stmt->execute(['user_id' => $userId]);
            } else {
                $stmt = $pdo->prepare(
                    'SELECT * FROM processing_jobs WHERE session_hash = :session_hash ORDER BY created_at DESC LIMIT ' . max(1, $limit)
                );
                $stmt->execute(['session_hash' => (string) $sessionHash]);
            }
            $rows = $stmt->fetchAll();
            return array_values(array_map([$this, 'hydrateRow'], $rows));
        }

        $store = $this->connection->readFileStore();
        $matches = [];
        foreach (($store['processing_jobs'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowUser = (string) ($row['user_id'] ?? '');
            $rowSession = (string) ($row['session_hash'] ?? '');
            if (($userId !== null && $userId !== '' && $rowUser === $userId)
                || ($sessionHash !== null && $sessionHash !== '' && $rowSession === $sessionHash)
            ) {
                $matches[] = $this->hydrateRow($row);
            }
        }
        usort($matches, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return array_slice($matches, 0, max(1, $limit));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(int $limit = 100, ?string $statusFilter = null): array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return [];
            }
            if ($statusFilter !== null && $statusFilter !== '') {
                $stmt = $pdo->prepare('SELECT * FROM processing_jobs WHERE status = :status ORDER BY created_at DESC LIMIT ' . max(1, $limit));
                $stmt->execute(['status' => $statusFilter]);
            } else {
                $stmt = $pdo->query('SELECT * FROM processing_jobs ORDER BY created_at DESC LIMIT ' . max(1, $limit));
            }
            $rows = $stmt ? $stmt->fetchAll() : [];
            return array_values(array_map([$this, 'hydrateRow'], $rows));
        }

        $store = $this->connection->readFileStore();
        $list = [];
        foreach (($store['processing_jobs'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($statusFilter !== null && $statusFilter !== '' && (string) ($row['status'] ?? '') !== $statusFilter) {
                continue;
            }
            $list[] = $this->hydrateRow($row);
        }
        usort($list, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return array_slice($list, 0, max(1, $limit));
    }

    public function markDeleted(string $id, string $status = 'deleted_by_user'): void
    {
        $job = $this->find($id);
        if ($job === null) {
            return;
        }
        $job['status'] = $status;
        $job['deleted_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->save($job);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findExpiredActiveJobs(int $nowTimestamp): array
    {
        $nowIso = gmdate('Y-m-d\TH:i:s\Z', $nowTimestamp);

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return [];
            }
            $stmt = $pdo->prepare(
                'SELECT * FROM processing_jobs WHERE deleted_at IS NULL AND expires_at <= :now'
            );
            $stmt->execute(['now' => $nowIso]);
            $rows = $stmt->fetchAll();
            return array_values(array_map([$this, 'hydrateRow'], $rows));
        }

        $store = $this->connection->readFileStore();
        $expired = [];
        foreach (($store['processing_jobs'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['deleted_at'] ?? null) === null && (string) ($row['expires_at'] ?? '') <= $nowIso) {
                $expired[] = $this->hydrateRow($row);
            }
        }
        return $expired;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateRow(array $row): array
    {
        $summary = [];
        if (!empty($row['summary_json']) && is_string($row['summary_json'])) {
            $decoded = json_decode($row['summary_json'], true);
            if (is_array($decoded)) {
                $summary = $decoded;
            }
        }
        $row['summary'] = $summary;

        $params = [];
        if (!empty($row['request_params_json']) && is_string($row['request_params_json'])) {
            $decodedParams = json_decode($row['request_params_json'], true);
            if (is_array($decodedParams)) {
                $params = $decodedParams;
            }
        }
        $row['request_params'] = $params;
        return $row;
    }
}
