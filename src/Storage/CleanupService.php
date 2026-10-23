<?php

declare(strict_types=1);

namespace Docvera\Storage;

use Docvera\Core\Logger;
use Docvera\Database\JobRepository;
use Docvera\Database\PlatformRepository;

/**
 * Handles both explicit immediate deletion ("Delete Now") and scheduled TTL garbage collection.
 */
final class CleanupService
{
    private TempStorageManager $storage;
    private JobRepository $jobs;
    private Logger $logger;
    private int $retentionTtlMinutes;
    private ?PlatformRepository $platform;

    public function __construct(
        TempStorageManager $storage,
        JobRepository $jobs,
        Logger $logger,
        int $retentionTtlMinutes = 60,
        ?PlatformRepository $platform = null
    ) {
        $this->storage = $storage;
        $this->jobs = $jobs;
        $this->logger = $logger;
        $this->retentionTtlMinutes = max(1, $retentionTtlMinutes);
        $this->platform = $platform;
    }

    public function deleteJobNow(string $jobId): bool
    {
        $job = $this->jobs->find($jobId);
        if ($job !== null && in_array((string) ($job['status'] ?? ''), ['queued', 'processing'], true)) {
            $this->releaseSlotForJob($job);
        }

        $removed = $this->storage->deleteJobWorkspace($jobId);
        $this->jobs->markDeleted($jobId, 'deleted_by_user');
        $this->logger->info('job.deleted_by_user', ['job_id' => $jobId]);
        return $removed;
    }

    /**
     * Purges expired jobs tracked in the database AND orphaned directories on disk.
     *
     * @return array{expired_jobs_purged: int, orphaned_dirs_purged: int, staged_dirs_purged: int}
     */
    public function purgeExpired(?int $nowTimestamp = null): array
    {
        $now = $nowTimestamp ?? time();
        $expiredJobsPurged = 0;
        $orphanedDirsPurged = 0;
        $stagedDirsPurged = 0;

        // 1. Purge expired jobs from repository
        $expiredJobs = $this->jobs->findExpiredActiveJobs($now);
        foreach ($expiredJobs as $job) {
            $jobId = (string) $job['id'];
            if (in_array((string) ($job['status'] ?? ''), ['queued', 'processing'], true)) {
                $this->releaseSlotForJob($job);
            }
            $this->storage->deleteJobWorkspace($jobId);
            $this->jobs->markDeleted($jobId, 'expired_ttl');
            $expiredJobsPurged++;
        }

        // 2. Sweep storage/app/jobs for any orphaned job_* directories older than TTL
        $ttlSeconds = $this->retentionTtlMinutes * 60;
        $jobDirs = glob($this->storage->getJobsBaseDir() . '/job_*') ?: [];
        foreach ($jobDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $jobId = basename($dir);
            $record = $this->jobs->find($jobId);
            if ($record !== null && ($record['deleted_at'] ?? null) !== null) {
                $this->storage->removeDirectoryRecursive($dir);
                $orphanedDirsPurged++;
                continue;
            }
            $mtime = (int) @filemtime($dir);
            if ($mtime > 0 && ($now - $mtime) >= $ttlSeconds) {
                $this->storage->removeDirectoryRecursive($dir);
                if ($record !== null) {
                    $this->jobs->markDeleted($jobId, 'expired_ttl');
                }
                $orphanedDirsPurged++;
            }
        }

        // 3. Sweep storage/app/staged for expired router staging folders
        $stageDirs = glob($this->storage->getStagedBaseDir() . '/stg_*') ?: [];
        foreach ($stageDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $mtime = (int) @filemtime($dir);
            if ($mtime > 0 && ($now - $mtime) >= 1800) {
                $this->storage->removeDirectoryRecursive($dir);
                $stagedDirsPurged++;
            }
        }

        if ($expiredJobsPurged > 0 || $orphanedDirsPurged > 0 || $stagedDirsPurged > 0) {
            $this->logger->info('storage.cleanup_completed', [
                'expired_jobs' => $expiredJobsPurged,
                'orphaned_dirs' => $orphanedDirsPurged,
                'staged_dirs' => $stagedDirsPurged,
            ]);
        }

        return [
            'expired_jobs_purged' => $expiredJobsPurged,
            'orphaned_dirs_purged' => $orphanedDirsPurged,
            'staged_dirs_purged' => $stagedDirsPurged,
        ];
    }

    /**
     * @param array<string, mixed> $job
     */
    private function releaseSlotForJob(array $job): void
    {
        if ($this->platform === null) {
            return;
        }
        $params = is_array($job['request_params'] ?? null) ? $job['request_params'] : [];
        $subjectKey = (string) ($params['subject_key'] ?? '');
        $subjectType = (string) ($params['subject_type'] ?? 'user');
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
}

