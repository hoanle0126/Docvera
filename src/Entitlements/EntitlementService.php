<?php

declare(strict_types=1);

namespace Docvera\Entitlements;

use Docvera\Auth\Principal;
use Docvera\Core\Config;
use Docvera\Database\PlatformRepository;
use Docvera\Security\ValidationException;
use Docvera\Tools\ToolDefinition;

/**
 * Single authoritative Entitlement, Plan, Deployment Mode, and Concurrency-Safe Quota Service.
 * Individual tools and controllers never hard-code plan or quota rules; they query this service.
 */
final class EntitlementService
{
    private PlatformRepository $platform;
    private Config $config;

    public function __construct(PlatformRepository $platform, Config $config)
    {
        $this->platform = $platform;
        $this->config = $config;
    }

    public function getDeploymentMode(): string
    {
        return (string) $this->platform->getSetting('deployment_mode', 'saas');
    }

    public function isGuestAccessEnabled(): bool
    {
        $mode = $this->getDeploymentMode();
        if ($mode === 'private') {
            return false;
        }
        return (bool) $this->platform->getSetting('guest_tools_enabled', true);
    }

    public function isBillingEnabled(): bool
    {
        $mode = $this->getDeploymentMode();
        if (in_array($mode, ['free_ads', 'private'], true)) {
            return false;
        }
        return (bool) $this->platform->getSetting('billing_enabled', true);
    }

    public function areAdsVisibleForPrincipal(Principal $principal): bool
    {
        if ($principal->isAdmin()) {
            return false;
        }
        $mode = $this->getDeploymentMode();
        if ($mode === 'private') {
            return false;
        }
        $adsEnabledSetting = (bool) $this->platform->getSetting('ads_enabled', $mode === 'free_ads');
        if (!$adsEnabledSetting && $mode !== 'free_ads') {
            return false;
        }
        $ent = $this->resolvePlanEntitlements($principal);
        return (bool) ($ent['ads_visible'] ?? false);
    }

    /**
     * Resolves the effective plan record and entitlement map for a Principal.
     *
     * @return array<string, mixed>
     */
    public function resolvePlanForPrincipal(Principal $principal): array
    {
        $planId = $principal->isGuest() ? 'guest' : $principal->planId;
        $plan = $this->platform->findPlan($planId);
        if ($plan === null) {
            $plan = $this->platform->findPlan($principal->isGuest() ? 'guest' : 'free') ?? [
                'id' => $planId,
                'name' => ucfirst($planId),
                'entitlements' => $this->platform->defaultEntitlementsSchema(),
            ];
        }
        return $plan;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvePlanEntitlements(Principal $principal): array
    {
        $plan = $this->resolvePlanForPrincipal($principal);
        $ent = is_array($plan['entitlements'] ?? null)
            ? $plan['entitlements']
            : $this->platform->defaultEntitlementsSchema();

        if ($principal->apiEnabledOverride !== null) {
            $ent['api_access'] = $principal->apiEnabledOverride;
        }

        // Administrators have unrestricted access unless explicitly testing a restricted plan
        if ($principal->isAdmin() && $principal->planId === 'free') {
            $ent['api_access'] = true;
            $ent['ocr_allowed'] = true;
            $ent['batch_allowed'] = true;
            $ent['ops_per_day'] = max(10000, (int) ($ent['ops_per_day'] ?? 10000));
            $ent['ops_per_month'] = max(100000, (int) ($ent['ops_per_month'] ?? 100000));
            $ent['api_requests_per_day'] = max(10000, (int) ($ent['api_requests_per_day'] ?? 10000));
            $ent['api_requests_per_month'] = max(100000, (int) ($ent['api_requests_per_month'] ?? 100000));
            $ent['concurrent_jobs'] = max(10, (int) ($ent['concurrent_jobs'] ?? 10));
            $ent['ads_visible'] = false;
        }

        return $ent;
    }

    /**
     * Evaluates whether a Principal can view/execute a ToolDefinition (before inspecting individual uploaded files).
     */
    public function evaluateToolAccess(
        Principal $principal,
        ToolDefinition $tool,
        bool $isApi = false,
        ?int $nowTimestamp = null
    ): EntitlementDecision {
        $now = $nowTimestamp ?? time();
        $mode = $this->getDeploymentMode();
        $ent = $this->resolvePlanEntitlements($principal);

        $globalMaxFileMb = (int) $this->config->get('limits.max_file_size_mb', 50);
        $planMaxFileMb = max(1, (int) ($ent['max_file_size_mb'] ?? $globalMaxFileMb));
        $effectiveMaxFileMb = $tool->maxFileSizeMb > 0
            ? min($planMaxFileMb, $tool->maxFileSizeMb)
            : $planMaxFileMb;
        $effectiveMaxBytes = $effectiveMaxFileMb * 1024 * 1024;

        $planMaxFiles = max(1, (int) ($ent['max_files_per_op'] ?? 20));
        $effectiveMaxFiles = min($tool->maxFiles, $planMaxFiles);
        if (!((bool) ($ent['batch_allowed'] ?? true)) && $tool->minFiles <= 1) {
            $effectiveMaxFiles = 1;
        }

        $maxPages = max(1, (int) ($ent['max_pages_per_op'] ?? 500));
        $concurrent = max(1, (int) ($ent['concurrent_jobs'] ?? 2));
        $maxAdminRetention = max(1, (int) $this->platform->getSetting('max_retention_minutes', 1440));
        $retention = min($maxAdminRetention, max(1, (int) ($ent['retention_minutes'] ?? $tool->retentionMinutes)));
        $ocrAllowed = (bool) ($ent['ocr_allowed'] ?? true);
        $batchAllowed = (bool) ($ent['batch_allowed'] ?? true);
        $apiAccess = (bool) ($ent['api_access'] ?? false);
        $adsVisible = $this->areAdsVisibleForPrincipal($principal);

        // 1. Deployment mode check
        if ($principal->isGuest() && !$this->isGuestAccessEnabled()) {
            return new EntitlementDecision(
                allowed: false,
                reasonCode: 'authentication_required',
                message: $mode === 'private'
                    ? 'This Docvera instance is configured as a private internal workspace. Please sign in to access document tools.'
                    : 'Guest tool access is disabled by the administrator. Please sign in to continue.',
                httpStatus: 401,
                maxFileSizeMb: $effectiveMaxFileMb,
                maxFileSizeBytes: $effectiveMaxBytes,
                maxFilesPerOp: $effectiveMaxFiles,
                maxPagesPerOp: $maxPages,
                concurrentJobs: $concurrent,
                retentionMinutes: $retention,
                ocrAllowed: $ocrAllowed,
                batchAllowed: $batchAllowed,
                apiAccess: $apiAccess,
                adsVisible: $adsVisible
            );
        }

        // 2. Administrative enable/disable state on the tool
        if (!$tool->enabled) {
            return new EntitlementDecision(
                allowed: false,
                reasonCode: 'tool_disabled',
                message: sprintf('The "%s" tool has been disabled by the administrator.', $tool->name),
                httpStatus: 403,
                maxFileSizeMb: $effectiveMaxFileMb,
                maxFileSizeBytes: $effectiveMaxBytes,
                maxFilesPerOp: $effectiveMaxFiles,
                maxPagesPerOp: $maxPages,
                concurrentJobs: $concurrent,
                retentionMinutes: $retention,
                ocrAllowed: $ocrAllowed,
                batchAllowed: $batchAllowed,
                apiAccess: $apiAccess,
                adsVisible: $adsVisible
            );
        }

        // 3. Guest eligibility per tool
        if ($principal->isGuest() && !$tool->guestEligible) {
            return new EntitlementDecision(
                allowed: false,
                reasonCode: 'guest_restricted_tool',
                message: sprintf('The "%s" tool requires a registered account.', $tool->name),
                httpStatus: 403,
                maxFileSizeMb: $effectiveMaxFileMb,
                maxFileSizeBytes: $effectiveMaxBytes,
                maxFilesPerOp: $effectiveMaxFiles,
                maxPagesPerOp: $maxPages,
                concurrentJobs: $concurrent,
                retentionMinutes: $retention,
                ocrAllowed: $ocrAllowed,
                batchAllowed: $batchAllowed,
                apiAccess: $apiAccess,
                adsVisible: $adsVisible
            );
        }

        // 4. API eligibility & plan API access check
        if ($isApi) {
            if (!(bool) $this->platform->getSetting('api_global_enabled', true)) {
                return new EntitlementDecision(
                    allowed: false,
                    reasonCode: 'api_disabled',
                    message: 'The REST API is currently disabled by the system administrator.',
                    httpStatus: 403,
                    maxFileSizeMb: $effectiveMaxFileMb,
                    maxFileSizeBytes: $effectiveMaxBytes,
                    maxFilesPerOp: $effectiveMaxFiles,
                    maxPagesPerOp: $maxPages,
                    concurrentJobs: $concurrent,
                    retentionMinutes: $retention,
                    ocrAllowed: $ocrAllowed,
                    batchAllowed: $batchAllowed,
                    apiAccess: $apiAccess,
                    adsVisible: $adsVisible
                );
            }
            if (!$tool->apiEligible) {
                return new EntitlementDecision(
                    allowed: false,
                    reasonCode: 'tool_not_api_eligible',
                    message: sprintf('The "%s" tool is not exposed for REST API execution.', $tool->name),
                    httpStatus: 403,
                    maxFileSizeMb: $effectiveMaxFileMb,
                    maxFileSizeBytes: $effectiveMaxBytes,
                    maxFilesPerOp: $effectiveMaxFiles,
                    maxPagesPerOp: $maxPages,
                    concurrentJobs: $concurrent,
                    retentionMinutes: $retention,
                    ocrAllowed: $ocrAllowed,
                    batchAllowed: $batchAllowed,
                    apiAccess: $apiAccess,
                    adsVisible: $adsVisible
                );
            }
            if (!$apiAccess) {
                return new EntitlementDecision(
                    allowed: false,
                    reasonCode: 'plan_api_restricted',
                    message: 'Your current plan does not include REST API access. Please upgrade your plan to use /api/v1 endpoints.',
                    httpStatus: 403,
                    maxFileSizeMb: $effectiveMaxFileMb,
                    maxFileSizeBytes: $effectiveMaxBytes,
                    maxFilesPerOp: $effectiveMaxFiles,
                    maxPagesPerOp: $maxPages,
                    concurrentJobs: $concurrent,
                    retentionMinutes: $retention,
                    ocrAllowed: $ocrAllowed,
                    batchAllowed: $batchAllowed,
                    apiAccess: $apiAccess,
                    adsVisible: $adsVisible
                );
            }
            if (!$principal->hasApiScope('tools:execute', $tool->id)) {
                return new EntitlementDecision(
                    allowed: false,
                    reasonCode: 'insufficient_api_scope',
                    message: sprintf('Your API key lacks the required "tools:execute" or "tool:%s" scope.', $tool->id),
                    httpStatus: 403,
                    maxFileSizeMb: $effectiveMaxFileMb,
                    maxFileSizeBytes: $effectiveMaxBytes,
                    maxFilesPerOp: $effectiveMaxFiles,
                    maxPagesPerOp: $maxPages,
                    concurrentJobs: $concurrent,
                    retentionMinutes: $retention,
                    ocrAllowed: $ocrAllowed,
                    batchAllowed: $batchAllowed,
                    apiAccess: $apiAccess,
                    adsVisible: $adsVisible
                );
            }
        }

        // 5. OCR allowance check
        if ($tool->id === 'ocr-pdf' && !$ocrAllowed && !$principal->isAdmin()) {
            return new EntitlementDecision(
                allowed: false,
                reasonCode: 'ocr_not_entitled',
                message: 'Optical Character Recognition (OCR) is not included in your current plan.',
                httpStatus: 403,
                maxFileSizeMb: $effectiveMaxFileMb,
                maxFileSizeBytes: $effectiveMaxBytes,
                maxFilesPerOp: $effectiveMaxFiles,
                maxPagesPerOp: $maxPages,
                concurrentJobs: $concurrent,
                retentionMinutes: $retention,
                ocrAllowed: $ocrAllowed,
                batchAllowed: $batchAllowed,
                apiAccess: $apiAccess,
                adsVisible: $adsVisible
            );
        }

        // 6. Specific tool access in Plan or Tool Override
        if (!$principal->isAdmin()) {
            $planTools = is_array($ent['allowed_tools'] ?? null) ? $ent['allowed_tools'] : ['*'];
            if (!in_array('*', $planTools, true) && !in_array($tool->id, $planTools, true)) {
                return new EntitlementDecision(
                    allowed: false,
                    reasonCode: 'plan_tool_restricted',
                    message: sprintf('Your current plan does not include access to "%s".', $tool->name),
                    httpStatus: 403,
                    maxFileSizeMb: $effectiveMaxFileMb,
                    maxFileSizeBytes: $effectiveMaxBytes,
                    maxFilesPerOp: $effectiveMaxFiles,
                    maxPagesPerOp: $maxPages,
                    concurrentJobs: $concurrent,
                    retentionMinutes: $retention,
                    ocrAllowed: $ocrAllowed,
                    batchAllowed: $batchAllowed,
                    apiAccess: $apiAccess,
                    adsVisible: $adsVisible
                );
            }

            if ($tool->allowedPlans !== [] && !in_array('*', $tool->allowedPlans, true) && !in_array($principal->planId, $tool->allowedPlans, true)) {
                return new EntitlementDecision(
                    allowed: false,
                    reasonCode: 'tool_plan_override_restricted',
                    message: sprintf('"%s" is restricted to specific plans (%s).', $tool->name, implode(', ', $tool->allowedPlans)),
                    httpStatus: 403,
                    maxFileSizeMb: $effectiveMaxFileMb,
                    maxFileSizeBytes: $effectiveMaxBytes,
                    maxFilesPerOp: $effectiveMaxFiles,
                    maxPagesPerOp: $maxPages,
                    concurrentJobs: $concurrent,
                    retentionMinutes: $retention,
                    ocrAllowed: $ocrAllowed,
                    batchAllowed: $batchAllowed,
                    apiAccess: $apiAccess,
                    adsVisible: $adsVisible
                );
            }
        }

        // 7. Daily & Monthly Operation Quota Check
        $usage = $this->getUsageSummary($principal, $now);
        $opsDayLimit = (int) ($ent['ops_per_day'] ?? 25);
        $opsMonthLimit = (int) ($ent['ops_per_month'] ?? 500);

        if ($opsDayLimit > 0 && $usage['ops_today'] >= $opsDayLimit) {
            return new EntitlementDecision(
                allowed: false,
                reasonCode: 'daily_quota_exceeded',
                message: sprintf('Daily operation quota reached (%d / %d operations used today). Quota resets at 00:00 UTC.', $usage['ops_today'], $opsDayLimit),
                httpStatus: 429,
                maxFileSizeMb: $effectiveMaxFileMb,
                maxFileSizeBytes: $effectiveMaxBytes,
                maxFilesPerOp: $effectiveMaxFiles,
                maxPagesPerOp: $maxPages,
                concurrentJobs: $concurrent,
                retentionMinutes: $retention,
                ocrAllowed: $ocrAllowed,
                batchAllowed: $batchAllowed,
                apiAccess: $apiAccess,
                adsVisible: $adsVisible
            );
        }

        if ($opsMonthLimit > 0 && $usage['ops_this_month'] >= $opsMonthLimit) {
            return new EntitlementDecision(
                allowed: false,
                reasonCode: 'monthly_quota_exceeded',
                message: sprintf('Monthly operation quota reached (%d / %d operations used this month).', $usage['ops_this_month'], $opsMonthLimit),
                httpStatus: 429,
                maxFileSizeMb: $effectiveMaxFileMb,
                maxFileSizeBytes: $effectiveMaxBytes,
                maxFilesPerOp: $effectiveMaxFiles,
                maxPagesPerOp: $maxPages,
                concurrentJobs: $concurrent,
                retentionMinutes: $retention,
                ocrAllowed: $ocrAllowed,
                batchAllowed: $batchAllowed,
                apiAccess: $apiAccess,
                adsVisible: $adsVisible
            );
        }

        if ($isApi) {
            $apiDayLimit = (int) ($ent['api_requests_per_day'] ?? 0);
            $apiMonthLimit = (int) ($ent['api_requests_per_month'] ?? 0);
            if ($apiDayLimit > 0 && $usage['api_requests_today'] >= $apiDayLimit) {
                return new EntitlementDecision(
                    allowed: false,
                    reasonCode: 'api_daily_quota_exceeded',
                    message: sprintf('Daily API request quota exceeded (%d / %d requests today).', $usage['api_requests_today'], $apiDayLimit),
                    httpStatus: 429,
                    maxFileSizeMb: $effectiveMaxFileMb,
                    maxFileSizeBytes: $effectiveMaxBytes,
                    maxFilesPerOp: $effectiveMaxFiles,
                    maxPagesPerOp: $maxPages,
                    concurrentJobs: $concurrent,
                    retentionMinutes: $retention,
                    ocrAllowed: $ocrAllowed,
                    batchAllowed: $batchAllowed,
                    apiAccess: $apiAccess,
                    adsVisible: $adsVisible
                );
            }
            if ($apiMonthLimit > 0 && $usage['api_requests_this_month'] >= $apiMonthLimit) {
                return new EntitlementDecision(
                    allowed: false,
                    reasonCode: 'api_monthly_quota_exceeded',
                    message: sprintf('Monthly API request quota exceeded (%d / %d requests this month).', $usage['api_requests_this_month'], $apiMonthLimit),
                    httpStatus: 429,
                    maxFileSizeMb: $effectiveMaxFileMb,
                    maxFileSizeBytes: $effectiveMaxBytes,
                    maxFilesPerOp: $effectiveMaxFiles,
                    maxPagesPerOp: $maxPages,
                    concurrentJobs: $concurrent,
                    retentionMinutes: $retention,
                    ocrAllowed: $ocrAllowed,
                    batchAllowed: $batchAllowed,
                    apiAccess: $apiAccess,
                    adsVisible: $adsVisible
                );
            }
        }

        return new EntitlementDecision(
            allowed: true,
            reasonCode: 'ok',
            message: null,
            httpStatus: 200,
            maxFileSizeMb: $effectiveMaxFileMb,
            maxFileSizeBytes: $effectiveMaxBytes,
            maxFilesPerOp: $effectiveMaxFiles,
            maxPagesPerOp: $maxPages,
            concurrentJobs: $concurrent,
            retentionMinutes: $retention,
            ocrAllowed: $ocrAllowed,
            batchAllowed: $batchAllowed,
            apiAccess: $apiAccess,
            adsVisible: $adsVisible
        );
    }

    /**
     * Enforces batch file count, per-file size, and page count limits against the EntitlementDecision.
     *
     * @param list<array{path: string, safe_name?: string, original_name?: string, size?: int}> $files
     */
    public function assertBatchAndPageLimits(
        Principal $principal,
        EntitlementDecision $decision,
        ToolDefinition $tool,
        array $files,
        int $measuredTotalPages = 0
    ): void {
        if (!$decision->allowed) {
            throw new ValidationException($decision->message ?? 'Operation not permitted by your current plan.', $decision->httpStatus);
        }

        $fileCount = count($files);
        if ($fileCount > 1 && !$decision->batchAllowed && !$principal->isAdmin()) {
            throw new ValidationException(
                sprintf('Batch processing multiple files (%d uploaded) is not enabled on your current plan.', $fileCount),
                403
            );
        }

        if ($fileCount > $decision->maxFilesPerOp) {
            throw new ValidationException(
                sprintf('Your current plan permits at most %d files per operation (you provided %d).', $decision->maxFilesPerOp, $fileCount),
                422
            );
        }

        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            $size = isset($file['size']) ? (int) $file['size'] : (is_file($path) ? (int) filesize($path) : 0);
            if ($size > $decision->maxFileSizeBytes) {
                $name = (string) ($file['safe_name'] ?? $file['original_name'] ?? 'document');
                throw new ValidationException(
                    sprintf('File "%s" exceeds your plan limit of %d MB per file.', $name, $decision->maxFileSizeMb),
                    422
                );
            }
        }

        if ($measuredTotalPages > 0 && $measuredTotalPages > $decision->maxPagesPerOp) {
            throw new ValidationException(
                sprintf(
                    'Document page count (%d pages) exceeds your plan allowance of %d pages per operation.',
                    $measuredTotalPages,
                    $decision->maxPagesPerOp
                ),
                422
            );
        }
    }

    /**
     * Atomically reserves a concurrent execution slot for the Principal.
     */
    public function acquireConcurrentSlot(Principal $principal, int $maxConcurrent): void
    {
        $exceeded = false;
        $this->platform->mutateUsageBucket(
            $principal->subjectKey,
            $principal->isGuest() ? 'guest' : 'user',
            'concurrency',
            'live',
            static function (array $bucket) use ($maxConcurrent, &$exceeded): array {
                $active = max(0, (int) ($bucket['active_concurrent_jobs'] ?? 0));
                if ($active >= $maxConcurrent) {
                    $exceeded = true;
                    return $bucket;
                }
                $bucket['active_concurrent_jobs'] = $active + 1;
                return $bucket;
            }
        );

        if ($exceeded) {
            throw new ValidationException(
                sprintf('Maximum concurrent jobs limit reached (%d active). Please wait for your running operation to finish.', $maxConcurrent),
                429
            );
        }
    }

    /**
     * Releases a previously acquired concurrent execution slot.
     */
    public function releaseConcurrentSlot(Principal $principal): void
    {
        $this->releaseConcurrentSlotBySubjectKey(
            $principal->subjectKey,
            $principal->isGuest() ? 'guest' : 'user'
        );
    }

    /**
     * Releases a concurrent execution slot directly by subject key (used by async worker & cleanup).
     */
    public function releaseConcurrentSlotBySubjectKey(string $subjectKey, string $subjectType = 'user'): void
    {
        if ($subjectKey === '') {
            return;
        }
        $this->platform->mutateUsageBucket(
            $subjectKey,
            $subjectType,
            'concurrency',
            'live',
            static function (array $bucket): array {
                $active = max(0, (int) ($bucket['active_concurrent_jobs'] ?? 0));
                $bucket['active_concurrent_jobs'] = max(0, $active - 1);
                return $bucket;
            }
        );
    }

    /**
     * Atomically commits completed job usage to both daily and monthly buckets.
     */
    public function commitOperationUsage(
        Principal $principal,
        string $toolId,
        int $bytesProcessed,
        bool $isApi = false,
        ?int $nowTimestamp = null
    ): void {
        $now = $nowTimestamp ?? time();
        $dayKey = gmdate('Y-m-d', $now);
        $monthKey = gmdate('Y-m', $now);
        $subjectType = $principal->isGuest() ? 'guest' : 'user';
        $isOcr = $toolId === 'ocr-pdf';

        $updater = static function (array $bucket) use ($bytesProcessed, $isApi, $isOcr): array {
            $bucket['ops_count'] = ((int) ($bucket['ops_count'] ?? 0)) + 1;
            $bucket['bytes_processed'] = ((int) ($bucket['bytes_processed'] ?? 0)) + max(0, $bytesProcessed);
            if ($isOcr) {
                $bucket['ocr_ops_count'] = ((int) ($bucket['ocr_ops_count'] ?? 0)) + 1;
            }
            if ($isApi) {
                $bucket['api_requests_count'] = ((int) ($bucket['api_requests_count'] ?? 0)) + 1;
            }
            return $bucket;
        };

        $this->platform->mutateUsageBucket($principal->subjectKey, $subjectType, 'day', $dayKey, $updater);
        $this->platform->mutateUsageBucket($principal->subjectKey, $subjectType, 'month', $monthKey, $updater);
    }

    /**
     * Enforces per-minute API rate limits and returns rate-limit header values.
     *
     * @return array{limit: int, remaining: int, reset: int}
     */
    public function enforceApiRateLimit(Principal $principal, ?int $nowTimestamp = null): array
    {
        $now = $nowTimestamp ?? time();
        $ent = $this->resolvePlanEntitlements($principal);
        $planRateLimit = max(1, (int) ($ent['api_rate_limit_per_min'] ?? 60));
        $keyRateLimit = isset($principal->apiKey['rate_limit_per_min'])
            ? max(1, (int) $principal->apiKey['rate_limit_per_min'])
            : $planRateLimit;
        $limit = min($planRateLimit, $keyRateLimit);

        $minuteKey = gmdate('Y-m-d\TH:i', $now);
        $resetTimestamp = ((int) floor($now / 60) + 1) * 60;
        $subjectKey = isset($principal->apiKey['id'])
            ? 'apikey:' . (string) $principal->apiKey['id']
            : $principal->subjectKey;

        $exceeded = false;
        $updated = $this->platform->mutateUsageBucket(
            $subjectKey,
            'api_key',
            'minute',
            $minuteKey,
            static function (array $bucket) use ($limit, &$exceeded): array {
                $current = (int) ($bucket['api_requests_count'] ?? 0);
                if ($current >= $limit) {
                    $exceeded = true;
                    return $bucket;
                }
                $bucket['api_requests_count'] = $current + 1;
                return $bucket;
            }
        );

        $used = (int) ($updated['api_requests_count'] ?? 0);
        $remaining = max(0, $limit - $used);

        if ($exceeded) {
            throw new ValidationException(
                sprintf('API rate limit exceeded (%d requests per minute allowed). Retry after %d seconds.', $limit, max(1, $resetTimestamp - $now)),
                429
            );
        }

        return [
            'limit' => $limit,
            'remaining' => $remaining,
            'reset' => $resetTimestamp,
        ];
    }

    /**
     * Returns a complete snapshot of a Principal's current usage and plan limits.
     *
     * @return array<string, mixed>
     */
    public function getUsageSummary(Principal $principal, ?int $nowTimestamp = null): array
    {
        $now = $nowTimestamp ?? time();
        $dayKey = gmdate('Y-m-d', $now);
        $monthKey = gmdate('Y-m', $now);
        $subjectType = $principal->isGuest() ? 'guest' : 'user';

        $dayBucket = $this->platform->getUsageBucket($principal->subjectKey, $subjectType, 'day', $dayKey);
        $monthBucket = $this->platform->getUsageBucket($principal->subjectKey, $subjectType, 'month', $monthKey);
        $liveBucket = $this->platform->getUsageBucket($principal->subjectKey, $subjectType, 'concurrency', 'live');

        $plan = $this->resolvePlanForPrincipal($principal);
        $ent = $this->resolvePlanEntitlements($principal);

        return [
            'subject_key' => $principal->subjectKey,
            'plan_id' => (string) ($plan['id'] ?? $principal->planId),
            'plan_name' => (string) ($plan['name'] ?? ucfirst($principal->planId)),
            'period_day' => $dayKey,
            'period_month' => $monthKey,
            'ops_today' => (int) ($dayBucket['ops_count'] ?? 0),
            'ops_per_day_limit' => (int) ($ent['ops_per_day'] ?? 25),
            'ops_this_month' => (int) ($monthBucket['ops_count'] ?? 0),
            'ops_per_month_limit' => (int) ($ent['ops_per_month'] ?? 500),
            'bytes_today' => (int) ($dayBucket['bytes_processed'] ?? 0),
            'bytes_this_month' => (int) ($monthBucket['bytes_processed'] ?? 0),
            'ocr_ops_today' => (int) ($dayBucket['ocr_ops_count'] ?? 0),
            'api_requests_today' => (int) ($dayBucket['api_requests_count'] ?? 0),
            'api_requests_per_day_limit' => (int) ($ent['api_requests_per_day'] ?? 0),
            'api_requests_this_month' => (int) ($monthBucket['api_requests_count'] ?? 0),
            'api_requests_per_month_limit' => (int) ($ent['api_requests_per_month'] ?? 0),
            'active_concurrent_jobs' => (int) ($liveBucket['active_concurrent_jobs'] ?? 0),
            'concurrent_jobs_limit' => (int) ($ent['concurrent_jobs'] ?? 2),
            'max_file_size_mb' => (int) ($ent['max_file_size_mb'] ?? 50),
            'max_files_per_op' => (int) ($ent['max_files_per_op'] ?? 20),
            'max_pages_per_op' => (int) ($ent['max_pages_per_op'] ?? 500),
            'retention_minutes' => (int) ($ent['retention_minutes'] ?? 60),
            'ocr_allowed' => (bool) ($ent['ocr_allowed'] ?? true),
            'batch_allowed' => (bool) ($ent['batch_allowed'] ?? true),
            'api_access' => (bool) ($ent['api_access'] ?? false),
        ];
    }
}

