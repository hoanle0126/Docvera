<?php

declare(strict_types=1);

namespace Docvera\Tools;

use Docvera\Auth\Principal;
use Docvera\Capabilities\CapabilityDetector;
use Docvera\Core\Config;
use Docvera\Core\Logger;
use Docvera\Database\JobRepository;
use Docvera\Drivers\GhostscriptDriver;
use Docvera\Drivers\LibreOfficeDriver;
use Docvera\Drivers\NativePhpPdfDriver;
use Docvera\Drivers\PdfInspector;
use Docvera\Drivers\PopplerDriver;
use Docvera\Drivers\ProcessingDriverInterface;
use Docvera\Drivers\QpdfDriver;
use Docvera\Drivers\TesseractOcrDriver;
use Docvera\Entitlements\EntitlementService;
use Docvera\Security\SafeProcessRunner;
use Docvera\Security\UploadValidator;
use Docvera\Security\ValidationException;
use Docvera\Storage\StagedFile;
use Docvera\Storage\TempStorageManager;
use RuntimeException;
use Throwable;

/**
 * Orchestrates end-to-end tool execution for both Web UI and REST API (sync & async):
 * capability verification -> entitlement & quota verification -> upload validation ->
 * page-limit inspection -> isolated workspace allocation -> driver execution ->
 * output verification -> job persistence -> usage accounting -> cleanup on failure.
 */
final class ToolExecutionService
{
    private ToolRegistry $registry;
    private PdfInspector $inspector;
    private UploadValidator $validator;
    private TempStorageManager $storage;
    private JobRepository $jobs;
    private Logger $logger;
    private Config $config;
    private ?EntitlementService $entitlements;

    /** @var array<string, ProcessingDriverInterface> */
    private array $drivers;

    public function __construct(
        ToolRegistry $registry,
        CapabilityDetector $capabilities,
        SafeProcessRunner $runner,
        PdfInspector $inspector,
        UploadValidator $validator,
        TempStorageManager $storage,
        JobRepository $jobs,
        Logger $logger,
        Config $config,
        ?EntitlementService $entitlements = null
    ) {
        $this->registry = $registry;
        $this->inspector = $inspector;
        $this->validator = $validator;
        $this->storage = $storage;
        $this->jobs = $jobs;
        $this->logger = $logger;
        $this->config = $config;
        $this->entitlements = $entitlements;

        $this->drivers = [
            'ghostscript' => new GhostscriptDriver($capabilities, $runner, $inspector),
            'poppler' => new PopplerDriver($capabilities, $runner, $inspector),
            'native_php_pdf' => new NativePhpPdfDriver($capabilities),
            'qpdf' => new QpdfDriver($capabilities, $runner, $inspector),
            'libreoffice' => new LibreOfficeDriver($capabilities, $runner, $inspector),
            'tesseract' => new TesseractOcrDriver($capabilities, $runner),
        ];
    }

    /**
     * @param list<array{path: string, original_name: string, size?: int, error?: int}> $rawFiles
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function execute(
        string $toolId,
        array $rawFiles,
        array $options,
        string $sessionIdentity,
        ?Principal $principal = null,
        string $source = 'web',
        bool $async = false
    ): array {
        $tool = $this->registry->get($toolId);
        if ($tool === null) {
            throw new ValidationException(sprintf('Unknown document tool "%s".', $toolId), 404);
        }

        $isApi = $source === 'api' || ($principal !== null && $principal->isApiCall());
        $sessionHash = $this->storage->hashToken($sessionIdentity);

        if ($principal === null) {
            $principal = new Principal(
                mode: 'guest',
                userId: null,
                email: null,
                name: 'Guest',
                role: 'guest',
                planId: 'guest',
                subjectKey: 'guest:' . substr($sessionHash, 0, 32),
                sessionIdentity: $sessionIdentity
            );
        }

        $decision = null;
        if ($this->entitlements !== null) {
            $decision = $this->entitlements->evaluateToolAccess($principal, $tool, $isApi);
            if (!$decision->allowed) {
                throw new ValidationException(
                    $decision->message ?? 'This operation is not permitted by your current plan.',
                    $decision->httpStatus
                );
            }
        }

        if (!$tool->availability->available || $tool->availability->activeDriver === null) {
            throw new ValidationException(
                $tool->availability->unavailableReason
                    ?? sprintf('The "%s" tool is currently unavailable on this server.', $tool->name),
                422
            );
        }

        $activeDriverKey = $tool->availability->activeDriver;
        $driver = $this->drivers[$activeDriverKey] ?? null;
        if ($driver === null || !$driver->isAvailable()) {
            throw new ValidationException(
                sprintf('Processing driver "%s" is not available on this server.', $activeDriverKey),
                422
            );
        }

        $maxFilesAllowed = $decision !== null ? $decision->maxFilesPerOp : $tool->maxFiles;
        $maxFileBytesAllowed = $decision !== null ? $decision->maxFileSizeBytes : null;

        // Special allowance for text-to-pdf when direct text_content is provided without a file upload
        $validatedFiles = [];
        if ($tool->id === 'text-to-pdf' && $rawFiles === [] && trim((string) ($options['text_content'] ?? '')) !== '') {
            $validatedFiles = [];
        } else {
            if ($decision !== null && count($rawFiles) > 1 && !$decision->batchAllowed && !$principal->isAdmin()) {
                throw new ValidationException(
                    sprintf('Batch processing multiple files (%d uploaded) requires a plan with batch allowance.', count($rawFiles)),
                    403
                );
            }

            $validatedFiles = $this->validator->validateFiles(
                $rawFiles,
                $tool->supportedMimes,
                $tool->minFiles,
                $maxFilesAllowed,
                $maxFileBytesAllowed
            );
        }

        // Measure total PDF page count when applicable and enforce plan max_pages_per_op
        $measuredTotalPages = 0;
        foreach ($validatedFiles as $vf) {
            if ($vf['mime'] === 'application/pdf') {
                $pdfMeta = $this->inspector->inspect($vf['path']);
                $measuredTotalPages += max(1, (int) ($pdfMeta['page_count'] ?? 1));
            } else {
                $measuredTotalPages += 1;
            }
        }

        if ($this->entitlements !== null && $decision !== null) {
            $this->entitlements->assertBatchAndPageLimits($principal, $decision, $tool, $validatedFiles, $measuredTotalPages);
        }

        $jobId = $this->storage->generateJobId();
        $rawDownloadToken = $this->storage->generateDownloadToken();
        $downloadTokenHash = $this->storage->hashToken($rawDownloadToken);

        $retentionMinutes = $decision !== null
            ? $decision->retentionMinutes
            : (int) $this->config->get('limits.retention_ttl_minutes', $tool->retentionMinutes);

        $now = time();
        $createdAt = gmdate('Y-m-d\TH:i:s\Z', $now);
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', $now + ($retentionMinutes * 60));

        $totalInputBytes = array_sum(array_column($validatedFiles, 'size'));
        $apiKeyId = isset($principal->apiKey['id']) ? (string) $principal->apiKey['id'] : null;

        // Acquire concurrency slot if EntitlementService is active
        $slotAcquired = false;
        if ($this->entitlements !== null && $decision !== null) {
            $this->entitlements->acquireConcurrentSlot($principal, $decision->concurrentJobs);
            $slotAcquired = true;
        }

        try {
            $workspace = $this->storage->createJobWorkspace($jobId);
            $stagedInputs = $this->storage->stageJobInputs($jobId, $validatedFiles);

            if ($async) {
                $serializedStaged = array_map(static fn(StagedFile $sf): array => [
                    'internalPath' => $sf->internalPath,
                    'internalFilename' => $sf->internalFilename,
                    'originalSafeName' => $sf->originalSafeName,
                    'mimeType' => $sf->mimeType,
                    'extension' => $sf->extension,
                    'sizeBytes' => $sf->sizeBytes,
                ], $stagedInputs);

                $queuedRecord = [
                    'id' => $jobId,
                    'tool_id' => $tool->id,
                    'tool_name' => $tool->name,
                    'driver_used' => $activeDriverKey,
                    'status' => 'queued',
                    'session_hash' => $sessionHash,
                    'download_token_hash' => $downloadTokenHash,
                    'download_token' => $rawDownloadToken,
                    'user_id' => $principal->userId,
                    'api_key_id' => $apiKeyId,
                    'source' => $isApi ? 'api' : 'web',
                    'execution_mode' => 'async',
                    'page_count' => $measuredTotalPages,
                    'input_count' => max(1, count($validatedFiles)),
                    'input_bytes' => $totalInputBytes,
                    'output_filename' => null,
                    'output_mime' => null,
                    'output_bytes' => 0,
                    'summary' => ['queued' => true],
                    'request_params' => [
                        'options' => $options,
                        'staged_inputs' => $serializedStaged,
                        'subject_key' => $principal->subjectKey,
                        'subject_type' => $principal->isGuest() ? 'guest' : 'user',
                        'plan_id' => $principal->planId,
                        'retention_minutes' => $retentionMinutes,
                    ],
                    'error_message' => null,
                    'created_at' => $createdAt,
                    'completed_at' => null,
                    'expires_at' => $expiresAt,
                    'retention_minutes' => $retentionMinutes,
                    'deleted_at' => null,
                ];

                $this->jobs->save($queuedRecord);

                if ($this->entitlements !== null) {
                    $this->entitlements->commitOperationUsage($principal, $tool->id, $totalInputBytes, $isApi);
                }

                $this->logger->info('job.queued', [
                    'job_id' => $jobId,
                    'tool_id' => $tool->id,
                    'driver' => $activeDriverKey,
                    'source' => $isApi ? 'api' : 'web',
                ]);

                // Keep concurrency slot reserved until processQueuedJob() or cleanup finishes
                $slotAcquired = false;

                return $queuedRecord;
            }

            $driverResult = $driver->process($tool->id, $stagedInputs, $options, $workspace);

            if (!is_file($driverResult->outputPath) || (int) filesize($driverResult->outputPath) <= 0) {
                throw new RuntimeException('Processing finished without generating a valid output file.');
            }

            // Clean up intermediate work and input directories immediately after success, keeping only output/
            $this->storage->removeDirectoryRecursive($workspace['work']);
            $this->storage->removeDirectoryRecursive($workspace['input']);

            $completedAt = gmdate('Y-m-d\TH:i:s\Z');
            $jobRecord = [
                'id' => $jobId,
                'tool_id' => $tool->id,
                'tool_name' => $tool->name,
                'driver_used' => $driverResult->driverName,
                'status' => 'completed',
                'session_hash' => $sessionHash,
                'download_token_hash' => $downloadTokenHash,
                'download_token' => $rawDownloadToken,
                'user_id' => $principal->userId,
                'api_key_id' => $apiKeyId,
                'source' => $isApi ? 'api' : 'web',
                'execution_mode' => 'sync',
                'page_count' => $measuredTotalPages,
                'input_count' => max(1, count($validatedFiles)),
                'input_bytes' => $totalInputBytes,
                'output_filename' => $driverResult->outputFilename,
                'output_mime' => $driverResult->outputMimeType,
                'output_bytes' => $driverResult->outputBytes,
                'summary' => $driverResult->summary,
                'error_message' => null,
                'created_at' => $createdAt,
                'completed_at' => $completedAt,
                'expires_at' => $expiresAt,
                'retention_minutes' => $retentionMinutes,
                'deleted_at' => null,
            ];

            $this->jobs->save($jobRecord);

            if ($this->entitlements !== null) {
                $this->entitlements->commitOperationUsage($principal, $tool->id, $totalInputBytes, $isApi);
            }

            $this->logger->info('job.completed', [
                'job_id' => $jobId,
                'tool_id' => $tool->id,
                'driver' => $driverResult->driverName,
                'source' => $isApi ? 'api' : 'web',
                'input_count' => count($validatedFiles),
                'output_bytes' => $driverResult->outputBytes,
            ]);

            return $jobRecord;
        } catch (ValidationException $ve) {
            $this->storage->deleteJobWorkspace($jobId);
            throw $ve;
        } catch (Throwable $e) {
            $this->storage->deleteJobWorkspace($jobId);

            $this->logger->error('job.failed', [
                'job_id' => $jobId,
                'tool_id' => $tool->id,
                'driver' => $activeDriverKey,
                'error' => $e->getMessage(),
            ]);

            throw new ValidationException(
                'The document could not be processed. Please verify the file is not corrupted or password-locked and try again.',
                422
            );
        } finally {
            if ($slotAcquired && $this->entitlements !== null) {
                $this->entitlements->releaseConcurrentSlot($principal);
            }
        }
    }

    /**
     * Processes a single queued asynchronous job by ID.
     *
     * @return array<string, mixed>
     */
    public function processQueuedJob(string $jobId): array
    {
        $job = $this->jobs->find($jobId);
        if ($job === null) {
            throw new ValidationException(sprintf('Queued job "%s" not found.', $jobId), 404);
        }

        if ((string) ($job['status'] ?? '') !== 'queued') {
            return $job;
        }

        $params = is_array($job['request_params'] ?? null) ? $job['request_params'] : [];
        $subjectKey = (string) ($params['subject_key'] ?? '');
        $subjectType = (string) ($params['subject_type'] ?? 'user');

        $toolId = (string) ($job['tool_id'] ?? '');
        $tool = $this->registry->get($toolId);
        if ($tool === null || !$tool->availability->available || $tool->availability->activeDriver === null) {
            $job['status'] = 'failed';
            $job['error_message'] = 'Required tool or driver is no longer available on this server.';
            $this->jobs->save($job);
            $this->storage->deleteJobWorkspace($jobId);
            if ($this->entitlements !== null && $subjectKey !== '') {
                $this->entitlements->releaseConcurrentSlotBySubjectKey($subjectKey, $subjectType);
            }
            return $job;
        }

        $driverKey = $tool->availability->activeDriver;
        $driver = $this->drivers[$driverKey] ?? null;
        if ($driver === null || !$driver->isAvailable()) {
            $job['status'] = 'failed';
            $job['error_message'] = sprintf('Driver "%s" is not available.', $driverKey);
            $this->jobs->save($job);
            $this->storage->deleteJobWorkspace($jobId);
            if ($this->entitlements !== null && $subjectKey !== '') {
                $this->entitlements->releaseConcurrentSlotBySubjectKey($subjectKey, $subjectType);
            }
            return $job;
        }

        $job['status'] = 'processing';
        $this->jobs->save($job);

        $options = is_array($params['options'] ?? null) ? $params['options'] : [];
        $rawStaged = is_array($params['staged_inputs'] ?? null) ? $params['staged_inputs'] : [];

        $stagedInputs = [];
        foreach ($rawStaged as $item) {
            if (is_array($item) && isset($item['internalPath'])) {
                $stagedInputs[] = new StagedFile(
                    (string) $item['internalPath'],
                    (string) ($item['internalFilename'] ?? basename((string) $item['internalPath'])),
                    (string) ($item['originalSafeName'] ?? 'document'),
                    (string) ($item['mimeType'] ?? 'application/pdf'),
                    (string) ($item['extension'] ?? 'pdf'),
                    (int) ($item['sizeBytes'] ?? 0)
                );
            }
        }

        try {
            $workspace = $this->storage->createJobWorkspace($jobId);
            $driverResult = $driver->process($tool->id, $stagedInputs, $options, $workspace);

            if (!is_file($driverResult->outputPath) || (int) filesize($driverResult->outputPath) <= 0) {
                throw new RuntimeException('Async processing finished without generating an output file.');
            }

            $this->storage->removeDirectoryRecursive($workspace['work']);
            $this->storage->removeDirectoryRecursive($workspace['input']);

            $job['status'] = 'completed';
            $job['driver_used'] = $driverResult->driverName;
            $job['output_filename'] = $driverResult->outputFilename;
            $job['output_mime'] = $driverResult->outputMimeType;
            $job['output_bytes'] = $driverResult->outputBytes;
            $job['summary'] = $driverResult->summary;
            $job['completed_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $job['error_message'] = null;
            $this->jobs->save($job);

            $this->logger->info('job.async_completed', [
                'job_id' => $jobId,
                'tool_id' => $tool->id,
                'driver' => $driverResult->driverName,
                'output_bytes' => $driverResult->outputBytes,
            ]);

            return $job;
        } catch (Throwable $e) {
            $this->storage->deleteJobWorkspace($jobId);
            $job['status'] = 'failed';
            $job['error_message'] = $e instanceof ValidationException
                ? $e->getMessage()
                : 'Asynchronous document processing failed.';
            $job['completed_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $this->jobs->save($job);

            $this->logger->error('job.async_failed', [
                'job_id' => $jobId,
                'tool_id' => $tool->id,
                'error' => $e->getMessage(),
            ]);

            return $job;
        } finally {
            if ($this->entitlements !== null && $subjectKey !== '') {
                $this->entitlements->releaseConcurrentSlotBySubjectKey($subjectKey, $subjectType);
            }
        }
    }

    /**
     * Processes up to $limit pending queued jobs and returns the count processed.
     */
    public function processAllQueuedJobs(int $limit = 25): int
    {
        $queued = $this->jobs->findAll($limit, 'queued');
        $processed = 0;
        foreach ($queued as $job) {
            $this->processQueuedJob((string) $job['id']);
            $processed++;
        }
        return $processed;
    }
}
