<?php

declare(strict_types=1);

namespace Docvera\Entitlements;

/**
 * Value object returned by EntitlementService::evaluateToolAccess() and authorizeExecution().
 */
final class EntitlementDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reasonCode = 'ok',
        public readonly ?string $message = null,
        public readonly int $httpStatus = 200,
        public readonly int $maxFileSizeMb = 50,
        public readonly int $maxFileSizeBytes = 52428800,
        public readonly int $maxFilesPerOp = 20,
        public readonly int $maxPagesPerOp = 500,
        public readonly int $concurrentJobs = 2,
        public readonly int $retentionMinutes = 60,
        public readonly bool $ocrAllowed = true,
        public readonly bool $batchAllowed = true,
        public readonly bool $apiAccess = false,
        public readonly bool $adsVisible = false
    ) {
    }
}

