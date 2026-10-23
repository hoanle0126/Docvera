<?php

declare(strict_types=1);

namespace Docvera\Http;

use Docvera\Auth\Principal;
use Docvera\Core\Application;
use Docvera\Security\ValidationException;
use Throwable;

/**
 * Handles versioned /api/v1/* endpoints.
 * Invokes the exact same ToolRegistry, EntitlementService, and ToolExecutionService as the Web UI.
 */
final class ApiController
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(Request $request): never
    {
        $result = $this->dispatch($request);

        if (!headers_sent()) {
            foreach ($result['headers'] as $hKey => $hVal) {
                header($hKey . ': ' . $hVal);
            }
        }

        if (isset($result['download_path']) && is_string($result['download_path'])) {
            Response::download(
                $result['download_path'],
                (string) ($result['download_filename'] ?? 'document'),
                (string) ($result['download_mime'] ?? 'application/octet-stream')
            );
        }

        Response::json(
            is_array($result['body']) ? $result['body'] : ['ok' => false],
            $result['status']
        );
    }

    /**
     * Dispatches an API request and returns a structured HTTP response descriptor.
     * Can be invoked both over HTTP and directly inside automated test suites.
     *
     * @return array{
     *     status: int,
     *     headers: array<string, string>,
     *     body: array<string, mixed>,
     *     download_path?: string,
     *     download_filename?: string,
     *     download_mime?: string
     * }
     */
    public function dispatch(Request $request): array
    {
        $startTime = microtime(true);
        $requestId = 'req_' . bin2hex(random_bytes(8));
        $headers = [
            'X-Request-Id' => $requestId,
        ];

        $path = $request->path;
        $method = $request->method;

        // 1. Public / Capability & OpenAPI Discovery Endpoints
        if ($method === 'GET' && $path === '/api/v1/capabilities') {
            $report = $this->app->capabilities->toMachineReadableReport();
            $tools = [];
            foreach ($this->app->registry->all() as $id => $tool) {
                $tools[$id] = $tool->toArray();
            }
            $report['tools'] = $tools;
            $report['request_id'] = $requestId;
            return [
                'status' => 200,
                'headers' => $headers,
                'body' => $report,
            ];
        }

        if ($method === 'GET' && $path === '/api/v1/openapi.json') {
            $spec = $this->app->apiDocs->generateOpenApiSpec();
            return [
                'status' => 200,
                'headers' => $headers,
                'body' => $spec,
            ];
        }

        if ($method === 'GET' && $path === '/api/v1/tools') {
            $apiTools = [];
            foreach ($this->app->registry->apiCapableTools() as $id => $tool) {
                $apiTools[$id] = $tool->toArray();
            }
            return [
                'status' => 200,
                'headers' => $headers,
                'body' => [
                    'ok' => true,
                    'request_id' => $requestId,
                    'count' => count($apiTools),
                    'tools' => $apiTools,
                ],
            ];
        }

        // 2. Authenticated /api/v1/* Endpoints
        $principal = null;
        try {
            $principal = $this->app->auth->authenticateApiKey($request);
            $rateInfo = $this->app->entitlements->enforceApiRateLimit($principal);
            $headers['X-RateLimit-Limit'] = (string) $rateInfo['limit'];
            $headers['X-RateLimit-Remaining'] = (string) $rateInfo['remaining'];
            $headers['X-RateLimit-Reset'] = (string) $rateInfo['reset'];

            // GET /api/v1/usage
            if ($method === 'GET' && $path === '/api/v1/usage') {
                $summary = $this->app->entitlements->getUsageSummary($principal);
                $body = [
                    'ok' => true,
                    'request_id' => $requestId,
                    'usage' => $summary,
                ];
                $this->logAudit($request, $requestId, $principal, null, 200, $startTime, null, $body);
                return ['status' => 200, 'headers' => $headers, 'body' => $body];
            }

            // GET /api/v1/jobs/{job_id}
            if ($method === 'GET' && preg_match('#^/api/v1/jobs/(job_[a-f0-9]{32})$#', $path, $m)) {
                if (!$principal->hasApiScope('jobs:read')) {
                    throw new ValidationException('API key lacks required "jobs:read" scope.', 403);
                }
                $job = $this->findAuthorizedApiJob($m[1], $principal);
                $body = $this->formatJobApiResponse($job, $requestId);
                $this->logAudit($request, $requestId, $principal, (string) ($job['tool_id'] ?? null), 200, $startTime, null, null);
                return ['status' => 200, 'headers' => $headers, 'body' => $body];
            }

            // GET /api/v1/jobs/{job_id}/download
            if ($method === 'GET' && preg_match('#^/api/v1/jobs/(job_[a-f0-9]{32})/download$#', $path, $m)) {
                if (!$principal->hasApiScope('jobs:download')) {
                    throw new ValidationException('API key lacks required "jobs:download" scope.', 403);
                }
                $job = $this->findAuthorizedApiJob($m[1], $principal);
                if (($job['deleted_at'] ?? null) !== null || empty($job['output_filename'])) {
                    throw new ValidationException('Output file has been deleted or expired.', 410);
                }
                $outPath = $this->app->storage->getJobOutputPath($m[1], (string) $job['output_filename']);
                if (!is_file($outPath) || !is_readable($outPath)) {
                    throw new ValidationException('Output file is no longer on disk.', 410);
                }
                $this->logAudit($request, $requestId, $principal, (string) ($job['tool_id'] ?? null), 200, $startTime, null, null);
                return [
                    'status' => 200,
                    'headers' => $headers,
                    'body' => ['ok' => true, 'job_id' => $m[1], 'filename' => $job['output_filename']],
                    'download_path' => $outPath,
                    'download_filename' => (string) $job['output_filename'],
                    'download_mime' => (string) ($job['output_mime'] ?: 'application/octet-stream'),
                ];
            }

            // DELETE /api/v1/jobs/{job_id}
            if ($method === 'DELETE' && preg_match('#^/api/v1/jobs/(job_[a-f0-9]{32})$#', $path, $m)) {
                if (!$principal->hasApiScope('jobs:delete')) {
                    throw new ValidationException('API key lacks required "jobs:delete" scope.', 403);
                }
                $job = $this->findAuthorizedApiJob($m[1], $principal);
                $this->app->cleanup->deleteJobNow($m[1]);
                $body = [
                    'ok' => true,
                    'request_id' => $requestId,
                    'job_id' => $m[1],
                    'status' => 'deleted_by_user',
                    'message' => 'All temporary files for this job have been permanently purged.',
                ];
                $this->logAudit($request, $requestId, $principal, (string) ($job['tool_id'] ?? null), 200, $startTime, null, $body);
                return ['status' => 200, 'headers' => $headers, 'body' => $body];
            }

            // POST /api/v1/pdf/*, /api/v1/convert/*, /api/v1/tools/*
            if ($method === 'POST') {
                $tool = $this->app->registry->resolveByApiRoute($path);
                if ($tool === null) {
                    throw new ValidationException(sprintf('Unknown API endpoint "%s".', $path), 404);
                }

                if (!$tool->enabled || !$tool->apiEligible) {
                    throw new ValidationException(
                        sprintf('The "%s" tool is not enabled for REST API execution.', $tool->name),
                        403
                    );
                }

                // Idempotency-Key support
                $idempotencyKey = trim((string) ($request->server['HTTP_IDEMPOTENCY_KEY'] ?? $request->input('idempotency_key', '')));
                $apiKeyId = (string) ($principal->apiKey['id'] ?? '');
                if ($idempotencyKey !== '' && $apiKeyId !== '') {
                    $cached = $this->app->platform->findIdempotentResponse($apiKeyId, $path, $idempotencyKey);
                    if ($cached !== null) {
                        $headers['X-Idempotent-Replay'] = 'true';
                        $replayBody = $cached['response'];
                        $replayBody['idempotent_replay'] = true;
                        return [
                            'status' => $cached['status_code'],
                            'headers' => $headers,
                            'body' => $replayBody,
                        ];
                    }
                }

                $rawFiles = $request->uploadedFiles('files');
                if ($rawFiles === []) {
                    $rawFiles = $request->uploadedFiles('file');
                }

                $options = [];
                foreach ($tool->options as $optField) {
                    $options[$optField->name] = $request->input($optField->name, $optField->default);
                }
                if ($request->input('text_content') !== null) {
                    $options['text_content'] = (string) $request->input('text_content');
                }

                $asyncParam = $request->input('async', '0');
                $async = in_array(strtolower((string) $asyncParam), ['1', 'true', 'yes'], true);

                $jobRecord = $this->app->executor->execute(
                    $tool->id,
                    $rawFiles,
                    $options,
                    $principal->sessionIdentity,
                    $principal,
                    'api',
                    $async
                );

                $statusCode = $async ? 202 : 200;
                $body = $this->formatJobApiResponse($jobRecord, $requestId);

                $this->logAudit(
                    $request,
                    $requestId,
                    $principal,
                    $tool->id,
                    $statusCode,
                    $startTime,
                    $idempotencyKey !== '' ? $idempotencyKey : null,
                    $body
                );

                return [
                    'status' => $statusCode,
                    'headers' => $headers,
                    'body' => $body,
                ];
            }

            throw new ValidationException(sprintf('Endpoint "%s %s" not found.', $method, $path), 404);
        } catch (ValidationException $ve) {
            $status = ($ve->getCode() >= 400 && $ve->getCode() < 600) ? $ve->getCode() : 422;
            $errorCode = match ($status) {
                401 => 'authentication_error',
                403 => 'entitlement_or_scope_restricted',
                404 => 'not_found',
                410 => 'resource_expired_or_deleted',
                429 => 'rate_or_quota_limit_exceeded',
                default => 'validation_error',
            };
            $errBody = [
                'ok' => false,
                'request_id' => $requestId,
                'error' => [
                    'code' => $errorCode,
                    'message' => $ve->getMessage(),
                    'status' => $status,
                ],
            ];
            $this->logAudit($request, $requestId, $principal, null, $status, $startTime, null, null);
            return ['status' => $status, 'headers' => $headers, 'body' => $errBody];
        } catch (Throwable) {
            $errBody = [
                'ok' => false,
                'request_id' => $requestId,
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'An unexpected processing error occurred. Temporary files have been cleaned up.',
                    'status' => 500,
                ],
            ];
            $this->logAudit($request, $requestId, $principal, null, 500, $startTime, null, null);
            return ['status' => 500, 'headers' => $headers, 'body' => $errBody];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function findAuthorizedApiJob(string $jobId, Principal $principal): array
    {
        $job = $this->app->jobs->find($jobId);
        if ($job === null) {
            throw new ValidationException('Job not found.', 404);
        }

        if ($principal->isAdmin()) {
            return $job;
        }

        $jobUserId = (string) ($job['user_id'] ?? '');
        if ($jobUserId === '' || $jobUserId !== $principal->userId) {
            throw new ValidationException('You do not have permission to access this job.', 403);
        }

        return $job;
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function formatJobApiResponse(array $job, string $requestId): array
    {
        $base = rtrim((string) $this->app->config->get('url', 'http://localhost:8080'), '/');
        $jobId = (string) $job['id'];
        $status = (string) ($job['status'] ?? 'completed');

        return [
            'ok' => $status !== 'failed',
            'request_id' => $requestId,
            'job_id' => $jobId,
            'tool_id' => $job['tool_id'] ?? '',
            'status' => $status,
            'execution_mode' => $job['execution_mode'] ?? 'sync',
            'driver_used' => $job['driver_used'] ?? '',
            'input_count' => (int) ($job['input_count'] ?? 1),
            'input_bytes' => (int) ($job['input_bytes'] ?? 0),
            'page_count' => (int) ($job['page_count'] ?? 0),
            'output_filename' => $job['output_filename'] ?? null,
            'output_mime' => $job['output_mime'] ?? null,
            'output_bytes' => (int) ($job['output_bytes'] ?? 0),
            'summary' => $job['summary'] ?? [],
            'error_message' => $job['error_message'] ?? null,
            'created_at' => $job['created_at'] ?? null,
            'completed_at' => $job['completed_at'] ?? null,
            'expires_at' => $job['expires_at'] ?? null,
            'deleted_at' => $job['deleted_at'] ?? null,
            'status_url' => $base . '/api/v1/jobs/' . $jobId,
            'download_url' => $status === 'completed' && ($job['deleted_at'] ?? null) === null
                ? ($base . '/api/v1/jobs/' . $jobId . '/download')
                : null,
            'delete_url' => $base . '/api/v1/jobs/' . $jobId,
        ];
    }

    /**
     * @param array<string, mixed>|null $cachedResponse
     */
    private function logAudit(
        Request $request,
        string $requestId,
        ?Principal $principal,
        ?string $toolId,
        int $statusCode,
        float $startTime,
        ?string $idempotencyKey,
        ?array $cachedResponse
    ): void {
        $durationMs = (int) round((microtime(true) - $startTime) * 1000);
        $this->app->platform->recordApiAuditLog([
            'request_id' => $requestId,
            'api_key_id' => isset($principal?->apiKey['id']) ? (string) $principal->apiKey['id'] : null,
            'user_id' => $principal?->userId,
            'endpoint' => $request->path,
            'method' => $request->method,
            'tool_id' => $toolId,
            'status_code' => $statusCode,
            'ip_address' => (string) ($request->server['REMOTE_ADDR'] ?? '127.0.0.1'),
            'duration_ms' => max(1, $durationMs),
            'idempotency_key' => $idempotencyKey,
            'response' => $cachedResponse,
        ]);
    }
}

