<?php

declare(strict_types=1);

namespace Docvera\Core;

use Docvera\Api\ApiDocGenerator;
use Docvera\Auth\AuthManager;
use Docvera\Billing\BillingService;
use Docvera\Capabilities\CapabilityDetector;
use Docvera\Database\Connection;
use Docvera\Database\JobRepository;
use Docvera\Database\Migrator;
use Docvera\Database\PlatformRepository;
use Docvera\Drivers\PdfInspector;
use Docvera\Entitlements\EntitlementService;
use Docvera\Security\SafeProcessRunner;
use Docvera\Security\UploadValidator;
use Docvera\Storage\CleanupService;
use Docvera\Storage\TempStorageManager;
use Docvera\Tools\ToolExecutionService;
use Docvera\Tools\ToolRegistry;

final class Application
{
    public readonly Config $config;
    public readonly Logger $logger;
    public readonly Connection $database;
    public readonly Migrator $migrator;
    public readonly JobRepository $jobs;
    public readonly PlatformRepository $platform;
    public readonly SafeProcessRunner $runner;
    public readonly CapabilityDetector $capabilities;
    public readonly PdfInspector $inspector;
    public readonly UploadValidator $validator;
    public readonly TempStorageManager $storage;
    public readonly CleanupService $cleanup;
    public readonly AuthManager $auth;
    public readonly EntitlementService $entitlements;
    public readonly BillingService $billing;
    public readonly ToolRegistry $registry;
    public readonly ToolExecutionService $executor;
    public readonly ApiDocGenerator $apiDocs;
    private readonly bool $usingDefaultEnvSecret;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? Config::fromDefaultPath();

        date_default_timezone_set((string) $this->config->get('timezone', 'UTC'));

        $rawSecret = trim((string) $this->config->get('secret', ''));
        $placeholderSecrets = [
            '',
            'change-this-to-a-64-char-random-secret-in-production-environment',
            'docvera-default-hmac-key-replace-in-production-environment-2026',
        ];
        if (in_array($rawSecret, $placeholderSecrets, true) || strlen($rawSecret) < 32) {
            $this->usingDefaultEnvSecret = true;
            $dbDir = (string) $this->config->get('paths.database');
            if (!is_dir($dbDir)) {
                @mkdir($dbDir, 0700, true);
            }
            $secretFile = rtrim($dbDir, '/') . '/.app_secret';
            $persistedSecret = is_file($secretFile) ? trim((string) @file_get_contents($secretFile)) : '';
            if (strlen($persistedSecret) < 64) {
                $persistedSecret = bin2hex(random_bytes(32));
                @file_put_contents($secretFile, $persistedSecret);
                @chmod($secretFile, 0600);
            }
            $this->config->set('secret', $persistedSecret);
        } else {
            $this->usingDefaultEnvSecret = false;
        }

        $this->logger = new Logger((string) $this->config->get('paths.logs'));
        $this->database = new Connection($this->config);
        $this->migrator = new Migrator($this->database, (string) $this->config->get('paths.migrations'));
        $this->migrator->migrate();

        $this->jobs = new JobRepository($this->database);
        $this->platform = new PlatformRepository($this->database, $this->config);
        $this->runner = new SafeProcessRunner((int) $this->config->get('limits.process_timeout_seconds', 60));
        $this->capabilities = new CapabilityDetector($this->config, $this->database, $this->runner);
        $this->inspector = new PdfInspector($this->capabilities, $this->runner);

        $this->validator = new UploadValidator(
            (int) $this->config->get('limits.max_file_size_bytes', 50 * 1024 * 1024),
            (int) $this->config->get('limits.max_files_per_job', 20)
        );

        $this->storage = new TempStorageManager(
            (string) $this->config->get('paths.jobs'),
            (string) $this->config->get('paths.staged'),
            (string) $this->config->get('secret')
        );

        $defaultRetention = (int) $this->platform->getSetting(
            'default_retention_minutes',
            (int) $this->config->get('limits.retention_ttl_minutes', 60)
        );

        $this->cleanup = new CleanupService(
            $this->storage,
            $this->jobs,
            $this->logger,
            $defaultRetention,
            $this->platform
        );

        $this->auth = new AuthManager($this->platform, $this->storage);
        $this->entitlements = new EntitlementService($this->platform, $this->config);
        $this->billing = new BillingService($this->platform, $this->logger);
        $this->registry = new ToolRegistry($this->capabilities, $this->config, $this->platform);

        $this->executor = new ToolExecutionService(
            $this->registry,
            $this->capabilities,
            $this->runner,
            $this->inspector,
            $this->validator,
            $this->storage,
            $this->jobs,
            $this->logger,
            $this->config,
            $this->entitlements
        );

        $this->apiDocs = new ApiDocGenerator($this->registry, $this->config);
    }

    public function isUsingDefaultSecret(): bool
    {
        return $this->usingDefaultEnvSecret;
    }

    public function getInstalledLockPath(): string
    {
        return (string) $this->config->get(
            'paths.installed_lock',
            (string) $this->config->get('paths.storage') . '/app/installed.lock'
        );
    }

    public function isInstalled(): bool
    {
        $lockPath = $this->getInstalledLockPath();
        if (is_file($lockPath)) {
            return true;
        }
        if ((bool) $this->config->get('installed', false)) {
            return true;
        }
        return $this->platform->listUsers() !== [];
    }

    public function markInstalled(string $adminEmail = ''): void
    {
        $lockPath = $this->getInstalledLockPath();
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $payload = json_encode([
            'installed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'version' => (string) $this->config->get('version', '2.0.0'),
            'admin_email' => $adminEmail,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($lockPath, (string) $payload);
        @chmod($lockPath, 0600);
    }
}
