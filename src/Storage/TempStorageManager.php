<?php

declare(strict_types=1);

namespace Docvera\Storage;

use RuntimeException;

/**
 * Manages isolated temporary working directories outside the public web root.
 * Provides randomized internal filenames, path traversal protection, and session-scoped staging.
 */
final class TempStorageManager
{
    private string $jobsBaseDir;
    private string $stagedBaseDir;
    private string $secret;

    public function __construct(string $jobsBaseDir, string $stagedBaseDir, string $secret)
    {
        $this->jobsBaseDir = rtrim($jobsBaseDir, '/');
        $this->stagedBaseDir = rtrim($stagedBaseDir, '/');
        $this->secret = $secret;

        foreach ([$this->jobsBaseDir, $this->stagedBaseDir] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
        }
    }

    public function generateJobId(): string
    {
        return 'job_' . bin2hex(random_bytes(16));
    }

    public function generateDownloadToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    public function hashToken(string $rawToken): string
    {
        return hash_hmac('sha256', $rawToken, $this->secret);
    }

    public function assertSafeIdentifier(string $id): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]{8,80}$/', $id)) {
            throw new RuntimeException('Invalid storage identifier.');
        }
    }

    /**
     * @return array{root: string, input: string, output: string, work: string}
     */
    public function createJobWorkspace(string $jobId): array
    {
        $this->assertSafeIdentifier($jobId);
        $root = $this->jobsBaseDir . '/' . $jobId;
        $input = $root . '/input';
        $output = $root . '/output';
        $work = $root . '/work';

        foreach ([$root, $input, $output, $work] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException('Unable to allocate isolated job workspace.');
            }
        }

        return [
            'root' => $root,
            'input' => $input,
            'output' => $output,
            'work' => $work,
        ];
    }

    /**
     * @param list<array{path: string, safe_name: string, mime: string, extension: string, size: int}> $validatedFiles
     * @return list<StagedFile>
     */
    public function stageJobInputs(string $jobId, array $validatedFiles): array
    {
        $workspace = $this->createJobWorkspace($jobId);
        $staged = [];

        foreach ($validatedFiles as $idx => $file) {
            $ext = preg_replace('/[^a-z0-9]/', '', strtolower($file['extension'])) ?: 'bin';
            $internalName = sprintf('in_%03d_%s.%s', $idx + 1, bin2hex(random_bytes(6)), $ext);
            $destPath = $workspace['input'] . '/' . $internalName;

            if (!@copy($file['path'], $destPath)) {
                throw new RuntimeException('Failed to copy input file into isolated job workspace.');
            }
            @chmod($destPath, 0600);

            $staged[] = new StagedFile(
                $destPath,
                $internalName,
                $file['safe_name'],
                $file['mime'],
                $ext,
                $file['size']
            );
        }

        return $staged;
    }

    public function getJobOutputPath(string $jobId, string $outputFilename): string
    {
        $this->assertSafeIdentifier($jobId);
        $cleanFile = basename(str_replace(['\\', "\0"], '/', $outputFilename));
        if ($cleanFile === '' || $cleanFile === '.' || $cleanFile === '..') {
            throw new RuntimeException('Invalid output filename.');
        }

        $root = realpath($this->jobsBaseDir . '/' . $jobId . '/output');
        if ($root === false) {
            return $this->jobsBaseDir . '/' . $jobId . '/output/' . $cleanFile;
        }
        return $root . '/' . $cleanFile;
    }

    public function deleteJobWorkspace(string $jobId): bool
    {
        $this->assertSafeIdentifier($jobId);
        $root = $this->jobsBaseDir . '/' . $jobId;
        if (!is_dir($root)) {
            return true;
        }
        return $this->removeDirectoryRecursive($root);
    }

    public function jobWorkspaceExists(string $jobId): bool
    {
        $this->assertSafeIdentifier($jobId);
        return is_dir($this->jobsBaseDir . '/' . $jobId);
    }

    /**
     * Stages a single file uploaded via the Homepage Smart Router so the user can pick a compatible tool without re-uploading.
     *
     * @param array{path: string, safe_name: string, mime: string, extension: string, size: int} $validatedFile
     * @param array<string, mixed> $inspectionMetadata
     * @return array<string, mixed>
     */
    public function stageRouterFile(array $validatedFile, string $sessionHash, array $inspectionMetadata = []): array
    {
        $stageId = 'stg_' . bin2hex(random_bytes(16));
        $dir = $this->stagedBaseDir . '/' . $stageId;
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create staging directory.');
        }

        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($validatedFile['extension'])) ?: 'bin';
        $internalName = 'staged_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $destPath = $dir . '/' . $internalName;

        if (!@copy($validatedFile['path'], $destPath)) {
            throw new RuntimeException('Failed to store staged file.');
        }
        @chmod($destPath, 0600);

        $manifest = [
            'stage_id' => $stageId,
            'session_hash' => $sessionHash,
            'internal_filename' => $internalName,
            'safe_name' => $validatedFile['safe_name'],
            'mime' => $validatedFile['mime'],
            'extension' => $ext,
            'size' => $validatedFile['size'],
            'inspection' => $inspectionMetadata,
            'created_at' => time(),
            'expires_at' => time() + 1800,
        ];

        file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $manifest;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveStagedRouterFile(string $stageId, string $sessionHash): ?array
    {
        if (!preg_match('/^stg_[a-f0-9]{32}$/', $stageId)) {
            return null;
        }
        $dir = $this->stagedBaseDir . '/' . $stageId;
        $manifestFile = $dir . '/manifest.json';
        if (!is_file($manifestFile)) {
            return null;
        }
        $raw = @file_get_contents($manifestFile);
        if ($raw === false) {
            return null;
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            return null;
        }
        if (!hash_equals((string) ($manifest['session_hash'] ?? ''), $sessionHash)) {
            return null;
        }
        if ((int) ($manifest['expires_at'] ?? 0) < time()) {
            $this->removeDirectoryRecursive($dir);
            return null;
        }
        $filePath = $dir . '/' . basename((string) ($manifest['internal_filename'] ?? ''));
        if (!is_file($filePath)) {
            return null;
        }
        $manifest['path'] = $filePath;
        return $manifest;
    }

    public function getJobsBaseDir(): string
    {
        return $this->jobsBaseDir;
    }

    public function getStagedBaseDir(): string
    {
        return $this->stagedBaseDir;
    }

    public function removeDirectoryRecursive(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }
        $items = scandir($dir);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $target = $dir . '/' . $item;
            if (is_dir($target) && !is_link($target)) {
                $this->removeDirectoryRecursive($target);
            } else {
                @unlink($target);
            }
        }
        return @rmdir($dir);
    }
}

