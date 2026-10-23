<?php

declare(strict_types=1);

namespace Docvera\Core;

final class Env
{
    private static bool $loaded = false;
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $envFilePath): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_file($envFilePath) || !is_readable($envFilePath)) {
            return;
        }

        $lines = file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $pos = strpos($trimmed, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $pos));
            $val = trim(substr($trimmed, $pos + 1));

            if (
                (str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))
            ) {
                $val = substr($val, 1, -1);
            }

            self::$values[$key] = $val;
        }
    }

    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }
        $env = getenv($key);
        if ($env !== false) {
            return (string) $env;
        }
        if (isset($_ENV[$key])) {
            return (string) $_ENV[$key];
        }
        return $default;
    }

    public static function int(string $key, int $default): int
    {
        $val = self::get($key);
        if ($val === null || $val === '' || !is_numeric($val)) {
            return $default;
        }
        return (int) $val;
    }

    public static function bool(string $key, bool $default): bool
    {
        $val = self::get($key);
        if ($val === null || $val === '') {
            return $default;
        }
        $normalized = strtolower(trim($val));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }
}

