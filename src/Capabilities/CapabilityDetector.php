<?php

declare(strict_types=1);

namespace Docvera\Capabilities;

use Docvera\Core\Config;
use Docvera\Database\Connection;
use Docvera\Security\SafeProcessRunner;

/**
 * Inspects and caches runtime environment capabilities (PHP runtime, extensions, storage, database,
 * and external document processing binaries).
 */
final class CapabilityDetector
{
    private Config $config;
    private Connection $database;
    private SafeProcessRunner $runner;

    /** @var array<string, CapabilityStatus>|null */
    private ?array $cached = null;

    public function __construct(Config $config, Connection $database, SafeProcessRunner $runner)
    {
        $this->config = $config;
        $this->database = $database;
        $this->runner = $runner;
    }

    /**
     * @return array<string, CapabilityStatus>
     */
    public function detectAll(bool $forceRefresh = false): array
    {
        if ($this->cached !== null && !$forceRefresh) {
            return $this->cached;
        }

        $capabilities = [];

        // 1. PHP Version
        $phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
        $capabilities['php_runtime'] = new CapabilityStatus(
            key: 'php_runtime',
            label: 'PHP Runtime (>= 8.2)',
            category: 'core',
            available: $phpOk,
            required: true,
            version: PHP_VERSION,
            binaryPath: PHP_BINARY,
            description: 'Core application runtime and native stream engine.',
            missingReason: $phpOk ? null : 'PHP 8.2 or newer is required to run Docvera.'
        );

        // 2. Required PHP Extensions
        $requiredExts = ['fileinfo', 'zip', 'mbstring', 'openssl', 'json'];
        $missingExts = [];
        foreach ($requiredExts as $ext) {
            if (!extension_loaded($ext)) {
                $missingExts[] = $ext;
            }
        }
        $extsOk = $missingExts === [];
        $capabilities['php_extensions'] = new CapabilityStatus(
            key: 'php_extensions',
            label: 'Core PHP Extensions',
            category: 'core',
            available: $extsOk,
            required: true,
            version: implode(', ', $requiredExts),
            binaryPath: null,
            description: 'MIME inspection (fileinfo), archive creation (zip), multibyte text, and cryptography.',
            missingReason: $extsOk ? null : 'Missing required PHP extensions: ' . implode(', ', $missingExts),
            meta: [
                'fileinfo' => extension_loaded('fileinfo'),
                'zip' => extension_loaded('zip'),
                'mbstring' => extension_loaded('mbstring'),
                'openssl' => extension_loaded('openssl'),
                'gd' => extension_loaded('gd'),
                'imagick' => extension_loaded('imagick'),
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            ]
        );

        // 3. Writable Storage
        $storageDirs = [
            'jobs' => (string) $this->config->get('paths.jobs'),
            'staged' => (string) $this->config->get('paths.staged'),
            'database' => (string) $this->config->get('paths.database'),
            'logs' => (string) $this->config->get('paths.logs'),
        ];
        $unwritable = [];
        foreach ($storageDirs as $label => $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                $unwritable[] = $label;
            }
        }
        $storageOk = $unwritable === [];
        $capabilities['writable_storage'] = new CapabilityStatus(
            key: 'writable_storage',
            label: 'Private Temporary Storage',
            category: 'core',
            available: $storageOk,
            required: true,
            version: $storageOk ? 'Writable (0700 isolated)' : 'Read-only',
            binaryPath: null,
            description: 'Non-public working directories for temporary document uploads, jobs, and logs.',
            missingReason: $storageOk ? null : 'Storage directories are not writable: ' . implode(', ', $unwritable)
        );

        // 4. Database Connection
        $dbHealthy = $this->database->isHealthy();
        $capabilities['database'] = new CapabilityStatus(
            key: 'database',
            label: 'Database & Job Store',
            category: 'core',
            available: $dbHealthy,
            required: true,
            version: strtoupper($this->database->getDriverName()),
            binaryPath: null,
            description: 'Stores job metadata, download tokens, retention timestamps, and migration state.',
            missingReason: $dbHealthy ? null : 'Database connection is unavailable or unconfigured.'
        );

        // 5. Native PHP PDF & Image Engine
        $capabilities['native_php_pdf'] = new CapabilityStatus(
            key: 'native_php_pdf',
            label: 'Native PHP PDF & Archive Engine',
            category: 'driver',
            available: $phpOk && $extsOk,
            required: true,
            version: 'PDF 1.7 Native',
            binaryPath: null,
            description: 'Built-in PDF 1.7 stream generator for Images to PDF, Text to PDF, and ZIP packaging.',
            missingReason: ($phpOk && $extsOk) ? null : 'Requires PHP 8.2+ and core extensions.'
        );

        // 6. Ghostscript (gs)
        $gsBin = $this->resolveBinary('gs', ['gs', 'gswin64c']);
        $gsVersion = null;
        if ($gsBin !== null) {
            $res = $this->runner->run([$gsBin, '--version'], null, 5);
            if ($res->isSuccessful()) {
                $gsVersion = trim($res->stdout);
            } else {
                $gsBin = null;
            }
        }
        $capabilities['ghostscript'] = new CapabilityStatus(
            key: 'ghostscript',
            label: 'Ghostscript Engine (gs)',
            category: 'driver',
            available: $gsBin !== null,
            required: false,
            version: $gsVersion,
            binaryPath: $gsBin,
            description: 'Provides PDF compression, grayscale conversion, flattening, encryption/unlocking, watermarks, page numbers, cropping, and page range extraction.',
            missingReason: $gsBin !== null ? null : 'Ghostscript (gs) is required for this PDF operation but was not detected on this server.'
        );

        // 7. Poppler Utilities (pdfinfo, pdftoppm, pdftotext, pdfseparate, pdfunite)
        $pdfinfoBin = $this->resolveBinary('pdfinfo', ['pdfinfo']);
        $pdftoppmBin = $this->resolveBinary('pdftoppm', ['pdftoppm']);
        $pdftotextBin = $this->resolveBinary('pdftotext', ['pdftotext']);
        $pdfseparateBin = $this->resolveBinary('pdfseparate', ['pdfseparate']);
        $pdfuniteBin = $this->resolveBinary('pdfunite', ['pdfunite']);

        $popplerVersion = null;
        if ($pdftoppmBin !== null) {
            $res = $this->runner->run([$pdftoppmBin, '-v'], null, 5);
            $combined = trim($res->stdout . "\n" . $res->stderr);
            if (preg_match('/version\s+([0-9.]+)/i', $combined, $m)) {
                $popplerVersion = $m[1];
            } else {
                $popplerVersion = 'Detected';
            }
        }
        $popplerAvailable = $pdfinfoBin !== null && $pdftoppmBin !== null;
        $capabilities['poppler'] = new CapabilityStatus(
            key: 'poppler',
            label: 'Poppler PDF Utilities',
            category: 'driver',
            available: $popplerAvailable,
            required: false,
            version: $popplerVersion,
            binaryPath: $pdftoppmBin,
            description: 'High-speed PDF structural inspection (pdfinfo), page-to-image rendering (pdftoppm), text extraction (pdftotext), and lossless page merging/splitting.',
            missingReason: $popplerAvailable ? null : 'Poppler utilities (pdfinfo / pdftoppm) were not detected on this server.',
            meta: [
                'pdfinfo' => $pdfinfoBin !== null,
                'pdftoppm' => $pdftoppmBin !== null,
                'pdftotext' => $pdftotextBin !== null,
                'pdfseparate' => $pdfseparateBin !== null,
                'pdfunite' => $pdfuniteBin !== null,
            ]
        );

        // 8. QPDF
        $qpdfBin = $this->resolveBinary('qpdf', ['qpdf']);
        $qpdfVersion = null;
        if ($qpdfBin !== null) {
            $res = $this->runner->run([$qpdfBin, '--version'], null, 5);
            if ($res->isSuccessful() && preg_match('/qpdf version\s+([^\s\r\n]+)/i', $res->stdout, $m)) {
                $qpdfVersion = $m[1];
            } elseif ($res->isSuccessful()) {
                $qpdfVersion = strtok(trim($res->stdout), "\r\n") ?: 'Detected';
            } else {
                $qpdfBin = null;
            }
        }
        $capabilities['qpdf'] = new CapabilityStatus(
            key: 'qpdf',
            label: 'QPDF Structural Engine',
            category: 'driver',
            available: $qpdfBin !== null,
            required: false,
            version: $qpdfVersion,
            binaryPath: $qpdfBin,
            description: 'Optional structural PDF transformation binary (automatically falls back to Ghostscript and Poppler when absent).',
            missingReason: $qpdfBin !== null ? null : 'QPDF binary (qpdf) was not detected on this server (Ghostscript/Poppler fallback active).'
        );

        // 9. LibreOffice Headless
        $loBin = $this->resolveBinary('libreoffice', ['libreoffice', 'soffice']);
        $loVersion = null;
        if ($loBin !== null) {
            $res = $this->runner->run([$loBin, '--version'], null, 8);
            if ($res->isSuccessful()) {
                $loVersion = strtok(trim($res->stdout), "\r\n") ?: 'Detected';
            } else {
                $loBin = null;
            }
        }
        $capabilities['libreoffice'] = new CapabilityStatus(
            key: 'libreoffice',
            label: 'LibreOffice Headless',
            category: 'driver',
            available: $loBin !== null,
            required: false,
            version: $loVersion,
            binaryPath: $loBin,
            description: 'Required for converting Word (.docx/.doc), Excel (.xlsx/.xls), and PowerPoint (.pptx/.ppt) files to PDF.',
            missingReason: $loBin !== null ? null : 'LibreOffice is required for this conversion but was not detected on this server.'
        );

        // 10. Tesseract OCR
        $tessBin = $this->resolveBinary('tesseract', ['tesseract']);
        $tessVersion = null;
        if ($tessBin !== null) {
            $res = $this->runner->run([$tessBin, '--version'], null, 5);
            $combined = trim($res->stdout . "\n" . $res->stderr);
            if ($res->isSuccessful() && $combined !== '') {
                $tessVersion = strtok($combined, "\r\n") ?: 'Detected';
            } else {
                $tessBin = null;
            }
        }
        $capabilities['tesseract'] = new CapabilityStatus(
            key: 'tesseract',
            label: 'Tesseract OCR Engine',
            category: 'driver',
            available: $tessBin !== null,
            required: false,
            version: $tessVersion,
            binaryPath: $tessBin,
            description: 'Required for optical character recognition (OCR) on scanned PDFs and raster images.',
            missingReason: $tessBin !== null ? null : 'Tesseract OCR is required for optical character recognition on scanned documents, but was not detected on this server.'
        );

        // 11. Queue & Scheduler
        $queueDriver = (string) $this->config->get('queue.driver', 'sync');
        $capabilities['queue'] = new CapabilityStatus(
            key: 'queue',
            label: 'Job Execution Pipeline',
            category: 'infrastructure',
            available: true,
            required: true,
            version: strtoupper($queueDriver),
            binaryPath: null,
            description: 'Manages job execution timeouts and isolated subprocess dispatch.'
        );

        $cleanupScript = (string) $this->config->get('base_path') . '/bin/cleanup';
        $schedulerReady = (bool) $this->config->get('queue.scheduler_enabled', true);
        $capabilities['scheduler'] = new CapabilityStatus(
            key: 'scheduler',
            label: 'TTL Cleanup Scheduler',
            category: 'infrastructure',
            available: $schedulerReady,
            required: true,
            version: (int) $this->config->get('limits.retention_ttl_minutes', 60) . 'm TTL',
            binaryPath: is_file($cleanupScript) ? $cleanupScript : null,
            description: 'Purges expired temporary files automatically after the configured retention window.'
        );

        $this->cached = $capabilities;
        return $capabilities;
    }

    public function get(string $key): ?CapabilityStatus
    {
        $all = $this->detectAll();
        return $all[$key] ?? null;
    }

    public function isAvailable(string $key): bool
    {
        return $this->get($key)?->available ?? false;
    }

    public function getBinaryPath(string $configKey, array $candidates = []): ?string
    {
        return $this->resolveBinary($configKey, $candidates ?: [$configKey]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toMachineReadableReport(): array
    {
        $all = $this->detectAll();
        $items = [];
        $coreReady = true;
        $availableCount = 0;
        $totalChecked = count($all);

        foreach ($all as $key => $cap) {
            $items[$key] = $cap->toArray();
            if ($cap->available) {
                $availableCount++;
            }
            if ($cap->required && !$cap->available) {
                $coreReady = false;
            }
        }

        return [
            'healthy' => $coreReady,
            'core_ready' => $coreReady,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'retention_ttl_minutes' => (int) $this->config->get('limits.retention_ttl_minutes', 60),
            'max_file_size_mb' => (int) $this->config->get('limits.max_file_size_mb', 50),
            'max_files_per_job' => (int) $this->config->get('limits.max_files_per_job', 20),
            'summary' => [
                'all_available' => $availableCount === $totalChecked,
                'core_ready' => $coreReady,
                'available_count' => $availableCount,
                'total_checked' => $totalChecked,
            ],
            'environment' => [
                'php_version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'db_driver' => $this->database->getDriverName(),
            ],
            'capabilities' => $items,
        ];
    }

    /**
     * @param list<string> $candidates
     */
    private function resolveBinary(string $configKey, array $candidates): ?string
    {
        $configured = trim((string) $this->config->get('binaries.' . $configKey, ''));
        if ($configured !== '') {
            if ($configured === '__DISABLED__') {
                return null;
            }
            if (is_file($configured) && is_executable($configured)) {
                return $configured;
            }
            return null;
        }

        $searchDirs = ['/usr/local/bin', '/usr/bin', '/bin', '/opt/homebrew/bin'];
        $pathEnv = getenv('PATH');
        if (is_string($pathEnv) && $pathEnv !== '') {
            foreach (explode(PATH_SEPARATOR, $pathEnv) as $dir) {
                $dir = rtrim(trim($dir), '/');
                if ($dir !== '' && !in_array($dir, $searchDirs, true)) {
                    $searchDirs[] = $dir;
                }
            }
        }

        foreach ($candidates as $binaryName) {
            foreach ($searchDirs as $dir) {
                $full = $dir . '/' . $binaryName;
                if (is_file($full) && is_executable($full)) {
                    return $full;
                }
            }
        }

        return null;
    }
}

