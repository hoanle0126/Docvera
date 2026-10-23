<?php

declare(strict_types=1);

use Docvera\Core\Env;

$basePath = dirname(__DIR__);

return [
    'name' => Env::get('APP_NAME', 'Docvera'),
    'version' => '2.0.0',
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => rtrim((string) Env::get('APP_URL', 'http://localhost:8080'), '/'),
    'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
    'installed' => Env::bool('APP_INSTALLED', false),
    'secret' => Env::get('APP_SECRET', 'docvera-default-hmac-key-replace-in-production-environment-2026'),
    'base_path' => $basePath,

    'paths' => [
        'base' => $basePath,
        'storage' => $basePath . '/storage',
        'jobs' => $basePath . '/storage/app/jobs',
        'staged' => $basePath . '/storage/app/staged',
        'database' => $basePath . '/storage/app/database',
        'installed_lock' => $basePath . '/storage/app/installed.lock',
        'logs' => $basePath . '/storage/logs',
        'migrations' => $basePath . '/database/migrations',
        'views' => $basePath . '/views',
        'public' => $basePath . '/public',
    ],

    'database' => [
        'connection' => Env::get('DB_CONNECTION', Env::get('DB_DRIVER', 'file')),
        'driver' => Env::get('DB_CONNECTION', Env::get('DB_DRIVER', 'file')),
        'dsn' => Env::get('DB_DSN', ''),
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::int('DB_PORT', 3306),
        'database' => Env::get('DB_DATABASE', 'docvera'),
        'username' => Env::get('DB_USERNAME', 'root'),
        'password' => Env::get('DB_PASSWORD', ''),
        'sqlite_path' => $basePath . '/' . ltrim((string) Env::get('DB_SQLITE_PATH', 'storage/app/database/docvera.sqlite'), '/'),
        'file_store_path' => $basePath . '/storage/app/database/docvera_store.json',
    ],

    'limits' => [
        'max_file_size_mb' => Env::int('MAX_FILE_SIZE_MB', Env::int('MAX_UPLOAD_SIZE_MB', 50)),
        'max_file_size_bytes' => Env::int('MAX_FILE_SIZE_MB', Env::int('MAX_UPLOAD_SIZE_MB', 50)) * 1024 * 1024,
        'max_files_per_job' => Env::int('MAX_FILES_PER_JOB', 20),
        'process_timeout_seconds' => Env::int('PROCESS_TIMEOUT_SECONDS', 60),
        'retention_ttl_minutes' => Env::int('RETENTION_TTL_MINUTES', 60),
    ],

    'queue' => [
        'driver' => Env::get('QUEUE_DRIVER', 'sync'),
        'scheduler_enabled' => Env::bool('SCHEDULER_ENABLED', true),
    ],

    'binaries' => [
        'gs' => Env::get('BIN_GS', ''),
        'qpdf' => Env::get('BIN_QPDF', ''),
        'pdfinfo' => Env::get('BIN_PDFINFO', ''),
        'pdftoppm' => Env::get('BIN_PDFTOPPM', ''),
        'pdftotext' => Env::get('BIN_PDFTOTEXT', ''),
        'pdfseparate' => Env::get('BIN_PDFSEPARATE', ''),
        'pdfunite' => Env::get('BIN_PDFUNITE', ''),
        'libreoffice' => Env::get('BIN_LIBREOFFICE', ''),
        'tesseract' => Env::get('BIN_TESSERACT', ''),
    ],
];

