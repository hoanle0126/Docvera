<?php

declare(strict_types=1);

namespace Docvera\Api;

use Docvera\Core\Config;
use Docvera\Tools\ToolDefinition;
use Docvera\Tools\ToolRegistry;

/**
 * Generates live developer documentation and OpenAPI 3.1 specifications directly
 * from the active ToolRegistry so documentation never drifts from code or admin overrides.
 */
final class ApiDocGenerator
{
    private ToolRegistry $registry;
    private Config $config;

    public function __construct(ToolRegistry $registry, Config $config)
    {
        $this->registry = $registry;
        $this->config = $config;
    }

    /**
     * Builds structured endpoint documentation for all enabled API-capable tools.
     *
     * @return list<array<string, mixed>>
     */
    public function getEndpointsDocumentation(?string $baseUrl = null): array
    {
        $base = rtrim($baseUrl ?? (string) $this->config->get('url', 'http://localhost:8080'), '/');
        $globalMaxMb = (int) $this->config->get('limits.max_file_size_mb', 50);
        $endpoints = [];

        foreach ($this->registry->apiCapableTools() as $tool) {
            $route = $tool->apiRoutePath !== '' ? $tool->apiRoutePath : ('/api/v1/tools/' . $tool->id);
            $effectiveMaxMb = $tool->maxFileSizeMb > 0 ? min($globalMaxMb, $tool->maxFileSizeMb) : $globalMaxMb;

            $optionsList = [];
            $curlFormParts = [];
            $extSample = ltrim($tool->supportedExtensions[0] ?? '.pdf', '.');

            if ($tool->minFiles > 1) {
                $curlFormParts[] = sprintf('  -F "files[]=@document-1.%s"', $extSample);
                $curlFormParts[] = sprintf('  -F "files[]=@document-2.%s"', $extSample);
            } else {
                $curlFormParts[] = sprintf('  -F "files[]=@document.%s"', $extSample);
            }

            foreach ($tool->options as $opt) {
                $optArr = $opt->toArray();
                $optionsList[] = $optArr;
                $sampleVal = (string) ($opt->default !== '' && $opt->default !== null ? $opt->default : ($opt->required ? 'sample_value' : ''));
                if ($sampleVal !== '') {
                    $curlFormParts[] = sprintf('  -F "%s=%s"', $opt->name, $sampleVal);
                }
            }

            $curlExample = sprintf(
                "curl -X POST \"%s%s\" \\\n  -H \"Authorization: Bearer dcv_live_YOUR_API_KEY\" \\\n  -H \"Idempotency-Key: req_%s_001\" \\\n%s",
                $base,
                $route,
                str_replace('-', '_', $tool->id),
                implode(" \\\n", $curlFormParts)
            );

            $endpoints[] = [
                'tool_id' => $tool->id,
                'name' => $tool->name,
                'description' => $tool->description,
                'category' => $tool->category,
                'method' => 'POST',
                'path' => $route,
                'alias_path' => '/api/v1/tools/' . $tool->id,
                'available_on_server' => $tool->availability->available,
                'active_driver' => $tool->availability->activeDriver,
                'unavailable_reason' => $tool->availability->unavailableReason,
                'authentication' => [
                    'headers' => ['Authorization: Bearer <api_key>', 'X-Api-Key: <api_key>'],
                    'required_scopes' => ['tools:execute', 'tool:' . $tool->id],
                ],
                'accepted_input' => [
                    'content_type' => 'multipart/form-data',
                    'file_field' => 'files[] (or file for single-file operations)',
                    'supported_mimes' => $tool->supportedMimes,
                    'supported_extensions' => $tool->supportedExtensions,
                    'min_files' => $tool->minFiles,
                    'max_files' => $tool->maxFiles,
                    'max_file_size_mb' => $effectiveMaxMb,
                ],
                'options' => $optionsList,
                'async_workflow' => [
                    'supported' => true,
                    'parameter' => 'async=1 (form field or query string)',
                    'queue_recommended' => $tool->queueRequired,
                    'status_endpoint' => 'GET /api/v1/jobs/{job_id}',
                    'download_endpoint' => 'GET /api/v1/jobs/{job_id}/download',
                    'delete_endpoint' => 'DELETE /api/v1/jobs/{job_id}',
                ],
                'example_curl' => $curlExample,
                'example_sync_response' => [
                    'ok' => true,
                    'request_id' => 'req_9f8e7d6c5b4a',
                    'job_id' => 'job_4f8a1b2c3d4e5f60718293a4b5c6d7e8',
                    'tool_id' => $tool->id,
                    'status' => 'completed',
                    'execution_mode' => 'sync',
                    'driver_used' => $tool->availability->activeDriver ?? ($tool->candidateDrivers[0] ?? 'ghostscript'),
                    'output_filename' => $tool->id . '-output.' . strtolower($extSample === 'pdf' && $tool->outputMime === 'application/zip' ? 'zip' : ($tool->outputMime === 'application/zip' ? 'zip' : 'pdf')),
                    'output_mime' => $tool->outputMime,
                    'output_bytes' => 48210,
                    'status_url' => $base . '/api/v1/jobs/job_4f8a1b2c3d4e5f60718293a4b5c6d7e8',
                    'download_url' => $base . '/api/v1/jobs/job_4f8a1b2c3d4e5f60718293a4b5c6d7e8/download',
                    'delete_url' => $base . '/api/v1/jobs/job_4f8a1b2c3d4e5f60718293a4b5c6d7e8',
                    'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + ($tool->retentionMinutes * 60)),
                ],
                'example_async_response' => [
                    'ok' => true,
                    'request_id' => 'req_1a2b3c4d5e6f',
                    'job_id' => 'job_4f8a1b2c3d4e5f60718293a4b5c6d7e8',
                    'tool_id' => $tool->id,
                    'status' => 'queued',
                    'execution_mode' => 'async',
                    'status_url' => $base . '/api/v1/jobs/job_4f8a1b2c3d4e5f60718293a4b5c6d7e8',
                    'download_url' => $base . '/api/v1/jobs/job_4f8a1b2c3d4e5f60718293a4b5c6d7e8/download',
                    'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + ($tool->retentionMinutes * 60)),
                ],
            ];
        }

        return $endpoints;
    }

    /**
     * @return list<array{code: string, http_status: int, description: string}>
     */
    public function getErrorCodesReference(): array
    {
        return [
            ['code' => 'missing_or_invalid_api_key', 'http_status' => 401, 'description' => 'The Authorization Bearer token or X-Api-Key header is missing, malformed, expired, or revoked.'],
            ['code' => 'insufficient_entitlement_or_scope', 'http_status' => 403, 'description' => 'The caller plan does not include API access, the tool is restricted, or the API key lacks the required scope.'],
            ['code' => 'resource_not_found', 'http_status' => 404, 'description' => 'The requested tool endpoint or job_id does not exist.'],
            ['code' => 'file_expired_or_purged', 'http_status' => 410, 'description' => 'The job output file has already been deleted via Delete Now or expired past the retention window.'],
            ['code' => 'validation_or_processing_error', 'http_status' => 422, 'description' => 'Uploaded file failed MIME/magic-byte verification, exceeded size/page limits, or required binary is unavailable.'],
            ['code' => 'rate_or_quota_limit_exceeded', 'http_status' => 429, 'description' => 'Per-minute API rate limit, concurrent job slot, or daily/monthly operation quota has been exhausted.'],
            ['code' => 'internal_processing_error', 'http_status' => 500, 'description' => 'Unexpected internal failure; temporary files are automatically cleaned up.'],
        ];
    }

    /**
     * Generates a machine-readable OpenAPI 3.1.0 specification from the live ToolRegistry.
     *
     * @return array<string, mixed>
     */
    public function generateOpenApiSpec(?string $baseUrl = null): array
    {
        $base = rtrim($baseUrl ?? (string) $this->config->get('url', 'http://localhost:8080'), '/');
        $paths = [
            '/api/v1/health' => [
                'get' => [
                    'summary' => 'Check service health and core dependency readiness',
                    'operationId' => 'getHealth',
                    'responses' => [
                        '200' => ['description' => 'Service is healthy and core capabilities are ready'],
                        '503' => ['description' => 'Core runtime or storage capability is degraded'],
                    ],
                ],
            ],
            '/api/v1/capabilities' => [
                'get' => [
                    'summary' => 'Inspect server capabilities and tool readiness',
                    'operationId' => 'getCapabilities',
                    'responses' => ['200' => ['description' => 'Capability report']],
                ],
            ],
            '/api/v1/tools' => [
                'get' => [
                    'summary' => 'List enabled API-capable document tools',
                    'operationId' => 'listApiTools',
                    'responses' => ['200' => ['description' => 'List of API-eligible tools']],
                ],
            ],
            '/api/v1/usage' => [
                'get' => [
                    'summary' => 'Get current plan quota and API usage telemetry',
                    'operationId' => 'getUsage',
                    'security' => [['BearerAuth' => []]],
                    'responses' => ['200' => ['description' => 'Usage summary']],
                ],
            ],
            '/api/v1/jobs' => [
                'get' => [
                    'summary' => 'List recent processing jobs for the authenticated account',
                    'operationId' => 'listJobs',
                    'security' => [['BearerAuth' => []]],
                    'responses' => ['200' => ['description' => 'List of jobs']],
                ],
            ],
            '/api/v1/jobs/{job_id}' => [
                'get' => [
                    'summary' => 'Get job status and result metadata',
                    'operationId' => 'getJobStatus',
                    'security' => [['BearerAuth' => []]],
                    'responses' => [
                        '200' => ['description' => 'Job status descriptor'],
                        '404' => ['description' => 'Job not found'],
                    ],
                ],
                'delete' => [
                    'summary' => 'Immediately delete job output files from temporary storage',
                    'operationId' => 'deleteJobFiles',
                    'security' => [['BearerAuth' => []]],
                    'responses' => ['200' => ['description' => 'Files purged']],
                ],
            ],
            '/api/v1/jobs/{job_id}/download' => [
                'get' => [
                    'summary' => 'Download processed output file',
                    'operationId' => 'downloadJobOutput',
                    'security' => [['BearerAuth' => []]],
                    'responses' => [
                        '200' => ['description' => 'Binary document stream'],
                        '410' => ['description' => 'File expired or already purged'],
                    ],
                ],
            ],
        ];

        foreach ($this->getEndpointsDocumentation($base) as $doc) {
            $props = [
                'files[]' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'format' => 'binary'],
                    'description' => sprintf('Accepted MIME types: %s', implode(', ', $doc['accepted_input']['supported_mimes'])),
                ],
                'async' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Set to true/1 to enqueue job asynchronously (HTTP 202).',
                ],
            ];
            foreach ($doc['options'] as $opt) {
                $props[(string) $opt['name']] = [
                    'type' => $opt['type'] === 'number' ? 'integer' : 'string',
                    'default' => $opt['default'],
                    'description' => $opt['label'],
                ];
            }

            $paths[(string) $doc['path']] = [
                'post' => [
                    'summary' => $doc['name'],
                    'description' => $doc['description'],
                    'operationId' => 'execute_' . str_replace('-', '_', (string) $doc['tool_id']),
                    'security' => [['BearerAuth' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'multipart/form-data' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => $props,
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Synchronous job completed'],
                        '202' => ['description' => 'Asynchronous job queued'],
                        '401' => ['description' => 'Authentication failed'],
                        '403' => ['description' => 'Plan or scope restriction'],
                        '422' => ['description' => 'Validation error'],
                        '429' => ['description' => 'Rate limit or quota exceeded'],
                    ],
                ],
            ];
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => (string) $this->config->get('name', 'Docvera') . ' REST API',
                'version' => '1.0.0',
                'description' => 'Self-hosted document processing REST API generated dynamically from the live Docvera Tool Registry.',
            ],
            'servers' => [['url' => $base]],
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'dcv_live_<hex>',
                    ],
                ],
            ],
            'paths' => $paths,
        ];
    }
}

