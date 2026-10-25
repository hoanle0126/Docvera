<?php

declare(strict_types=1);

/**
 * Docvera Self-Contained PSR-4 Autoloader
 *
 * Maps `Docvera\Tests\` -> `/tests/` and `Docvera\` -> `/src/` without requiring
 * external Composer runtime files, while seamlessly delegating to Composer's
 * generated autoloader if `vendor/composer/autoload_real.php` is present.
 */

spl_autoload_register(static function (string $class): void {
    $baseDir = dirname(__DIR__);
    $prefixes = [
        'Docvera\\Tests\\' => $baseDir . '/tests/',
        'Docvera\\' => $baseDir . '/src/',
    ];

    foreach ($prefixes as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $relativeClass = substr($class, strlen($prefix));
            $file = $dir . str_replace('\\', '/', $relativeClass) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    }
});
