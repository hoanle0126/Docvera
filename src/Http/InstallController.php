<?php

declare(strict_types=1);

namespace Docvera\Http;

use Docvera\Core\Application;
use Docvera\Core\Config;
use Docvera\Database\Connection;
use Docvera\Database\Migrator;
use Docvera\Database\PlatformRepository;
use Docvera\Security\CsrfGuard;
use Docvera\Security\ValidationException;
use PDO;
use Throwable;

/**
 * Guided Web & CLI Pre-Flight Installer for Docvera.
 *
 * Strictly separates REQUIRED host checks from OPTIONAL processing binaries,
 * verifies database connectivity without leaking secrets, writes .env with a
 * CSPRNG APP_SECRET, runs migrations, provisions the first Administrator account,
 * and writes storage/app/installed.lock so the installer cannot be re-executed.
 */
final class InstallController
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * @param callable(string, array<string, mixed>): string $renderView
     */
    public function handle(Request $request, callable $renderView): never
    {
        if ($this->app->isInstalled()) {
            if ($request->wantsJson()) {
                Response::json([
                    'ok' => false,
                    'error' => 'Docvera is already installed and locked. Remove storage/app/installed.lock via CLI only if you intend to re-run setup.',
                ], 403);
            }
            $html = $renderView('error', [
                'pageTitle' => 'Installer Locked — Docvera',
                'statusCode' => 403,
                'heading' => 'Installation is already complete and locked',
                'message' => 'For security, the web installer is permanently disabled once setup completes (storage/app/installed.lock). If you are the server administrator and need to re-run installation, remove storage/app/installed.lock via SSH/CLI.',
            ]);
            Response::html($html, 403);
        }

        $report = $this->buildPreflightReport();
        $errorMessage = null;
        $submitted = [
            'site_name' => (string) $request->input('site_name', (string) $this->app->config->get('name', 'Docvera')),
            'app_url' => (string) $request->input('app_url', $this->detectDefaultAppUrl($request)),
            'deployment_mode' => (string) $request->input('deployment_mode', 'saas'),
            'db_driver' => (string) $request->input('db_driver', (string) $this->app->config->get('database.driver', 'file')),
            'db_sqlite_path' => (string) $request->input('db_sqlite_path', 'storage/app/database/docvera.sqlite'),
            'db_dsn' => (string) $request->input('db_dsn', ''),
            'db_username' => (string) $request->input('db_username', ''),
            'default_retention_minutes' => (int) $request->input('default_retention_minutes', 60),
            'max_file_size_mb' => (int) $request->input('max_file_size_mb', 50),
            'admin_name' => (string) $request->input('admin_name', 'System Administrator'),
            'admin_email' => (string) $request->input('admin_email', 'admin@example.com'),
        ];

        if ($request->method === 'POST') {
            if (!CsrfGuard::verify((string) $request->input('_csrf', ''))) {
                $errorMessage = 'Security token expired. Please submit the installation form again.';
            } else {
                try {
                    $result = $this->executeInstall(array_merge($submitted, [
                        'db_password' => (string) $request->input('db_password', ''),
                        'admin_password' => (string) $request->input('admin_password', ''),
                    ]));

                    CsrfGuard::ensureSession();
                    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                        @session_regenerate_id(true);
                    }
                    CsrfGuard::rotateToken();
                    $_SESSION['auth_user_id'] = (string) $result['admin_user']['id'];

                    if ($request->wantsJson()) {
                        Response::json([
                            'ok' => true,
                            'message' => 'Docvera installation completed and locked.',
                            'redirect_url' => '/admin?saved=' . urlencode('Installation complete. Welcome to Docvera Administration.'),
                        ]);
                    }

                    Response::redirect('/admin?saved=' . urlencode('Installation complete. Welcome to Docvera Administration.'));
                } catch (ValidationException $ve) {
                    $errorMessage = $ve->getMessage();
                    if ($request->wantsJson()) {
                        Response::json(['ok' => false, 'error' => $errorMessage], 422);
                    }
                } catch (Throwable) {
                    $errorMessage = 'Installation failed due to an unexpected environment or database error. Please verify directory permissions and database settings.';
                    if ($request->wantsJson()) {
                        Response::json(['ok' => false, 'error' => $errorMessage], 500);
                    }
                }
            }
        }

        $html = $renderView('install', [
            'pageTitle' => 'Installation & Environment Readiness — Docvera',
            'seoIndexable' => false,
            'report' => $report,
            'submitted' => $submitted,
            'errorMessage' => $errorMessage,
        ]);
        Response::html($html);
    }

    /**
     * Builds the structured pre-flight report differentiating REQUIRED checks from OPTIONAL capabilities.
     *
     * @return array{
     *   all_required_passed: bool,
     *   required_checks: list<array<string, mixed>>,
     *   optional_capabilities: list<array<string, mixed>>,
     *   runtime_services: list<array<string, mixed>>
     * }
     */
    public function buildPreflightReport(): array
    {
        $basePath = (string) $this->app->config->get('paths.base');
        $storagePath = (string) $this->app->config->get('paths.storage');
        $jobsPath = (string) $this->app->config->get('paths.jobs');
        $stagedPath = (string) $this->app->config->get('paths.staged');
        $dbPath = (string) $this->app->config->get('paths.database');
        $logsPath = (string) $this->app->config->get('paths.logs');

        foreach ([$storagePath, $jobsPath, $stagedPath, $dbPath, $logsPath] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
        }

        $envPath = $basePath . '/.env';
        $envWritable = is_file($envPath) ? is_writable($envPath) : is_writable($basePath);

        $requiredChecks = [
            [
                'key' => 'php_version',
                'name' => 'PHP Runtime >= 8.2.0',
                'category' => 'REQUIRED',
                'passed' => version_compare(PHP_VERSION, '8.2.0', '>='),
                'current' => PHP_VERSION . ' (' . PHP_SAPI . ')',
                'remediation' => 'Upgrade PHP to 8.2 or 8.3 (e.g. sudo apt install php8.2-cli php8.2-fpm).',
            ],
            [
                'key' => 'ext_fileinfo',
                'name' => 'PHP Extension: fileinfo',
                'category' => 'REQUIRED',
                'passed' => extension_loaded('fileinfo'),
                'current' => extension_loaded('fileinfo') ? 'Loaded' : 'Missing',
                'remediation' => 'Enable ext-fileinfo in php.ini for strict magic-byte MIME verification.',
            ],
            [
                'key' => 'ext_openssl',
                'name' => 'PHP Extension: openssl',
                'category' => 'REQUIRED',
                'passed' => extension_loaded('openssl'),
                'current' => extension_loaded('openssl') ? 'Loaded' : 'Missing',
                'remediation' => 'Enable ext-openssl in php.ini for cryptographic operations and HTTPS webhook verification.',
            ],
            [
                'key' => 'ext_zip',
                'name' => 'PHP Extension: zip',
                'category' => 'REQUIRED',
                'passed' => extension_loaded('zip'),
                'current' => extension_loaded('zip') ? 'Loaded' : 'Missing',
                'remediation' => 'Install php-zip (e.g. sudo apt install php-zip) for multi-page ZIP bundle downloads.',
            ],
            [
                'key' => 'ext_mbstring',
                'name' => 'PHP Extension: mbstring',
                'category' => 'REQUIRED',
                'passed' => extension_loaded('mbstring'),
                'current' => extension_loaded('mbstring') ? 'Loaded' : 'Missing',
                'remediation' => 'Install php-mbstring (e.g. sudo apt install php-mbstring) for UTF-8 document text handling.',
            ],
            [
                'key' => 'dir_jobs',
                'name' => 'Writable Directory: storage/app/jobs',
                'category' => 'REQUIRED',
                'passed' => is_dir($jobsPath) && is_writable($jobsPath),
                'current' => is_dir($jobsPath) && is_writable($jobsPath) ? 'Writable (0700)' : 'Not Writable',
                'remediation' => 'chmod -R 0700 storage/app/jobs && chown -R www-data:www-data storage',
            ],
            [
                'key' => 'dir_staged',
                'name' => 'Writable Directory: storage/app/staged',
                'category' => 'REQUIRED',
                'passed' => is_dir($stagedPath) && is_writable($stagedPath),
                'current' => is_dir($stagedPath) && is_writable($stagedPath) ? 'Writable (0700)' : 'Not Writable',
                'remediation' => 'chmod -R 0700 storage/app/staged && chown -R www-data:www-data storage',
            ],
            [
                'key' => 'dir_database',
                'name' => 'Writable Directory: storage/app/database',
                'category' => 'REQUIRED',
                'passed' => is_dir($dbPath) && is_writable($dbPath),
                'current' => is_dir($dbPath) && is_writable($dbPath) ? 'Writable (0700)' : 'Not Writable',
                'remediation' => 'chmod -R 0700 storage/app/database && chown -R www-data:www-data storage',
            ],
            [
                'key' => 'dir_logs',
                'name' => 'Writable Directory: storage/logs',
                'category' => 'REQUIRED',
                'passed' => is_dir($logsPath) && is_writable($logsPath),
                'current' => is_dir($logsPath) && is_writable($logsPath) ? 'Writable (0700)' : 'Not Writable',
                'remediation' => 'chmod -R 0700 storage/logs && chown -R www-data:www-data storage',
            ],
            [
                'key' => 'env_writable',
                'name' => 'Configuration File Writable (.env)',
                'category' => 'REQUIRED',
                'passed' => $envWritable,
                'current' => $envWritable ? 'Writable' : 'Read-Only',
                'remediation' => 'Ensure the project root or .env file is writable by PHP during setup, or run php bin/install via CLI.',
            ],
        ];

        $allRequiredPassed = true;
        foreach ($requiredChecks as $chk) {
            if (!$chk['passed']) {
                $allRequiredPassed = false;
                break;
            }
        }

        $detected = $this->app->capabilities->detectAll(true);
        $optionalKeys = [
            'ghostscript' => [
                'impact' => 'Required for PDF Merge, Split, Extract, Delete, Reorder, Rotate, Compress, Watermark, Page Numbers, Crop, Metadata, Protect, and Unlock.',
                'remediation' => 'sudo apt install ghostscript (or brew install ghostscript)',
            ],
            'poppler' => [
                'impact' => 'Enables PDF to JPG, PDF to PNG, PDF to Text, and fast structural PDF inspection.',
                'remediation' => 'sudo apt install poppler-utils (or brew install poppler)',
            ],
            'qpdf' => [
                'impact' => 'Optional accelerator for lossless structural Merge, Rotate, and 256-bit AES Protect/Unlock PDF (falls back to Ghostscript when absent).',
                'remediation' => 'sudo apt install qpdf (or brew install qpdf)',
            ],
            'libreoffice' => [
                'impact' => 'Optional engine for Word/Excel/PowerPoint to PDF conversion. If absent, Office-to-PDF tools are gracefully disabled.',
                'remediation' => 'sudo apt install libreoffice-core libreoffice-writer libreoffice-calc libreoffice-impress',
            ],
            'tesseract' => [
                'impact' => 'Optional engine for OCR Scanned PDF / Image to Searchable Text. If absent, the OCR tool is gracefully disabled without blocking installation.',
                'remediation' => 'sudo apt install tesseract-ocr tesseract-ocr-eng',
            ],
        ];

        $optionalCapabilities = [
            [
                'key' => 'ext_gd',
                'name' => 'PHP Extension: gd / imagick',
                'category' => 'OPTIONAL CAPABILITY',
                'available' => extension_loaded('gd') || extension_loaded('imagick'),
                'version' => extension_loaded('gd') ? 'gd Loaded' : (extension_loaded('imagick') ? 'imagick Loaded' : 'Native Stream Parser Active'),
                'binary_path' => null,
                'impact' => 'Optional raster accelerator. Standard JPEG and 8-bit RGB PNG Image-to-PDF conversion works natively without ext-gd.',
                'remediation' => 'sudo apt install php-gd',
            ],
        ];
        foreach ($optionalKeys as $capKey => $meta) {
            $cap = $detected[$capKey] ?? null;
            $avail = $cap !== null && $cap->available;
            $optionalCapabilities[] = [
                'key' => $capKey,
                'name' => $cap !== null ? $cap->name : ucfirst($capKey),
                'category' => 'OPTIONAL CAPABILITY',
                'available' => $avail,
                'version' => $cap?->version ?? ($avail ? 'Detected' : 'Not Installed'),
                'binary_path' => $cap?->binaryPath,
                'impact' => $meta['impact'],
                'remediation' => $meta['remediation'],
            ];
        }

        $runtimeServices = [
            [
                'key' => 'queue_worker',
                'name' => 'Background Queue Worker (php bin/worker)',
                'category' => 'OPTIONAL SERVICE',
                'status' => 'Supported (Synchronous Fallback Active)',
                'description' => 'Web tools and default REST API requests execute synchronously out of the box. For asynchronous API jobs (?async=1), run `php bin/worker --daemon` under Supervisor/systemd or `php bin/worker --once` via cron.',
            ],
            [
                'key' => 'cron_scheduler',
                'name' => 'Scheduled Cleanup (php bin/cleanup)',
                'category' => 'RECOMMENDED CRON',
                'status' => 'Supported (Opportunistic HTTP Fallback Active)',
                'description' => 'Configure `*/10 * * * * php /path/to/bin/cleanup` in cron for deterministic background garbage collection. When cron is unavailable, Docvera automatically runs opportunistic TTL cleanup during web requests.',
            ],
        ];

        return [
            'all_required_passed' => $allRequiredPassed,
            'required_checks' => $requiredChecks,
            'optional_capabilities' => $optionalCapabilities,
            'runtime_services' => $runtimeServices,
        ];
    }

    /**
     * Executes the installation, writes .env, runs migrations, seeds settings, creates the initial Admin user,
     * and writes storage/app/installed.lock.
     *
     * @param array<string, mixed> $input
     * @return array{admin_user: array<string, mixed>, env_path: string, lock_path: string}
     */
    public function executeInstall(array $input): array
    {
        if ($this->app->isInstalled()) {
            throw new ValidationException('Docvera is already installed and locked.', 403);
        }

        $report = $this->buildPreflightReport();
        if (!$report['all_required_passed']) {
            throw new ValidationException('One or more REQUIRED server checks failed. Please resolve the required items highlighted above before installing.');
        }

        $siteName = trim((string) ($input['site_name'] ?? 'Docvera')) ?: 'Docvera';
        $appUrl = rtrim(trim((string) ($input['app_url'] ?? 'http://localhost:8080')), '/') ?: 'http://localhost:8080';
        $deploymentMode = (string) ($input['deployment_mode'] ?? 'saas');
        if (!in_array($deploymentMode, ['saas', 'free_ads', 'private'], true)) {
            $deploymentMode = 'saas';
        }

        $dbDriver = strtolower(trim((string) ($input['db_driver'] ?? 'file')));
        if (!in_array($dbDriver, ['file', 'sqlite', 'mysql'], true)) {
            throw new ValidationException('Selected database driver must be file, sqlite, or mysql.');
        }

        $adminName = trim((string) ($input['admin_name'] ?? 'System Administrator'));
        $adminEmail = strtolower(trim((string) ($input['admin_email'] ?? '')));
        $adminPassword = (string) ($input['admin_password'] ?? '');

        if ($adminName === '' || mb_strlen($adminName) > 100) {
            throw new ValidationException('Please provide a valid Administrator display name (1–100 characters).');
        }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Please provide a valid Administrator email address.');
        }
        if (strlen($adminPassword) < 8) {
            throw new ValidationException('Administrator password must be at least 8 characters long.');
        }

        $basePath = (string) $this->app->config->get('paths.base');
        $sqliteRelPath = trim((string) ($input['db_sqlite_path'] ?? 'storage/app/database/docvera.sqlite')) ?: 'storage/app/database/docvera.sqlite';
        $sqliteAbsPath = str_starts_with($sqliteRelPath, '/') ? $sqliteRelPath : ($basePath . '/' . ltrim($sqliteRelPath, '/'));
        $dbDsn = trim((string) ($input['db_dsn'] ?? ''));
        $dbUsername = trim((string) ($input['db_username'] ?? ''));
        $dbPassword = (string) ($input['db_password'] ?? '');

        // Pre-verify database connectivity without leaking credentials in error output
        $this->verifyDatabaseConnection($dbDriver, $sqliteAbsPath, $dbDsn, $dbUsername, $dbPassword);

        $retentionMinutes = max(5, min(10080, (int) ($input['default_retention_minutes'] ?? 60)));
        $maxFileSizeMb = max(1, min(500, (int) ($input['max_file_size_mb'] ?? 50)));
        $secret = bin2hex(random_bytes(32));

        $envPath = $basePath . '/.env';
        $envContent = $this->buildEnvFileContent([
            'APP_NAME' => $siteName,
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $appUrl,
            'APP_TIMEZONE' => 'UTC',
            'APP_INSTALLED' => 'true',
            'APP_SECRET' => $secret,
            'DB_CONNECTION' => $dbDriver,
            'DB_DRIVER' => $dbDriver,
            'DB_SQLITE_PATH' => $sqliteRelPath,
            'DB_DSN' => $dbDsn,
            'DB_USERNAME' => $dbUsername,
            'DB_PASSWORD' => $dbPassword,
            'MAX_FILE_SIZE_MB' => (string) $maxFileSizeMb,
            'MAX_FILES_PER_JOB' => '20',
            'RETENTION_TTL_MINUTES' => (string) $retentionMinutes,
            'PROCESS_TIMEOUT_SECONDS' => '60',
        ]);

        if (@file_put_contents($envPath, $envContent) === false) {
            throw new ValidationException('Unable to write .env configuration file. Check directory permissions.');
        }
        @chmod($envPath, 0600);

        // Update active config & re-run migrations on target database
        $this->app->config->set('name', $siteName);
        $this->app->config->set('url', $appUrl);
        $this->app->config->set('secret', $secret);
        $this->app->config->set('installed', true);
        $this->app->config->set('database.connection', $dbDriver);
        $this->app->config->set('database.driver', $dbDriver);
        $this->app->config->set('database.sqlite_path', $sqliteAbsPath);
        $this->app->config->set('database.dsn', $dbDsn);
        $this->app->config->set('database.username', $dbUsername);
        $this->app->config->set('database.password', $dbPassword);

        $conn = new Connection($this->app->config);
        $migrator = new Migrator($conn, (string) $this->app->config->get('paths.migrations'));
        $migrator->migrate();

        $platform = new PlatformRepository($conn, $this->app->config);
        $platform->setSettings([
            'site_name' => $siteName,
            'deployment_mode' => $deploymentMode,
            'registration_mode' => $deploymentMode === 'private' ? 'closed' : 'open',
            'registration_enabled' => $deploymentMode !== 'private',
            'guest_tools_enabled' => $deploymentMode !== 'private',
            'billing_enabled' => $deploymentMode === 'saas',
            'ads_enabled' => $deploymentMode === 'free_ads',
            'default_retention_minutes' => $retentionMinutes,
        ]);

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $existingUser = $platform->findUserByEmail($adminEmail);
        $adminUser = [
            'id' => $existingUser['id'] ?? ('usr_' . bin2hex(random_bytes(12))),
            'email' => $adminEmail,
            'name' => $adminName,
            'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
            'role' => 'admin',
            'plan_id' => 'business',
            'status' => 'active',
            'api_enabled_override' => 1,
            'created_at' => $existingUser['created_at'] ?? $now,
            'updated_at' => $now,
            'last_login_at' => $now,
        ];
        $platform->saveUser($adminUser);

        $this->app->markInstalled($adminEmail);

        return [
            'admin_user' => $adminUser,
            'env_path' => $envPath,
            'lock_path' => $this->app->getInstalledLockPath(),
        ];
    }

    private function verifyDatabaseConnection(
        string $driver,
        string $sqliteAbsPath,
        string $dsn,
        string $username,
        string $password
    ): void {
        if ($driver === 'file') {
            $dbDir = dirname((string) $this->app->config->get('database.file_store_path'));
            if (!is_dir($dbDir) && !@mkdir($dbDir, 0700, true)) {
                throw new ValidationException('Cannot create file-store database directory in storage/app/database.');
            }
            if (!is_writable($dbDir)) {
                throw new ValidationException('Database directory storage/app/database is not writable.');
            }
            return;
        }

        if ($driver === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) {
                throw new ValidationException('The pdo_sqlite PHP extension is not installed on this server. Select "file" (Concurrency-Safe JSON Store) or install php-sqlite3.');
            }
            $dir = dirname($sqliteAbsPath);
            if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
                throw new ValidationException('Cannot create directory for SQLite database file.');
            }
            try {
                $pdo = new PDO('sqlite:' . $sqliteAbsPath, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $pdo->exec('SELECT 1');
            } catch (Throwable) {
                throw new ValidationException('Could not initialize SQLite database at the specified path. Verify directory permissions.');
            }
            return;
        }

        if ($driver === 'mysql') {
            if (!extension_loaded('pdo_mysql') && !extension_loaded('pdo_pgsql')) {
                throw new ValidationException('Neither pdo_mysql nor pdo_pgsql is loaded in PHP. Install php-mysql or select the built-in File/SQLite driver.');
            }
            if ($dsn === '') {
                throw new ValidationException('Please provide a valid PDO DSN (for example: mysql:host=127.0.0.1;port=3306;dbname=docvera;charset=utf8mb4).');
            }
            try {
                $pdo = new PDO($dsn, $username !== '' ? $username : null, $password !== '' ? $password : null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ]);
                $pdo->query('SELECT 1');
            } catch (Throwable) {
                throw new ValidationException('Database connection failed. Please verify the DSN, host reachability, username, and password.');
            }
        }
    }

    /**
     * @param array<string, string> $pairs
     */
    private function buildEnvFileContent(array $pairs): string
    {
        $lines = [
            '# Docvera Production Environment Configuration',
            '# Generated by Docvera Installer on ' . gmdate('Y-m-d\TH:i:s\Z'),
            '',
        ];
        foreach ($pairs as $key => $val) {
            $cleanVal = str_replace(["\r", "\n"], '', $val);
            if ($cleanVal === '' || preg_match('/[\s#"\'\\\\]/', $cleanVal)) {
                $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $cleanVal);
                $lines[] = sprintf('%s="%s"', $key, $escaped);
            } else {
                $lines[] = sprintf('%s=%s', $key, $cleanVal);
            }
        }
        $lines[] = '';
        return implode("\n", $lines);
    }

    private function detectDefaultAppUrl(Request $request): string
    {
        $https = (!empty($request->server['HTTPS']) && strtolower((string) $request->server['HTTPS']) !== 'off')
            || strtolower((string) ($request->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($request->server['HTTP_HOST'] ?? 'localhost:8080');
        return $scheme . '://' . $host;
    }
}
