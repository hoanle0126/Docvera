<?php

declare(strict_types=1);

namespace Docvera\Core;

/**
 * Privacy-safe operational logger.
 * Never logs document text, file payloads, passwords, or raw internal server paths in user-facing contexts.
 */
final class Logger
{
    private string $logDirectory;

    public function __construct(string $logDirectory)
    {
        $this->logDirectory = rtrim($logDirectory, '/');
        if (!is_dir($this->logDirectory)) {
            @mkdir($this->logDirectory, 0750, true);
        }
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function info(string $event, array $context = []): void
    {
        $this->write('INFO', $event, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function warning(string $event, array $context = []): void
    {
        $this->write('WARN', $event, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function error(string $event, array $context = []): void
    {
        $this->write('ERROR', $event, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function write(string $level, string $event, array $context): void
    {
        $redacted = [];
        foreach ($context as $key => $value) {
            $lowerKey = strtolower((string) $key);
            if (
                str_contains($lowerKey, 'password') ||
                str_contains($lowerKey, 'secret') ||
                str_contains($lowerKey, 'token') ||
                str_contains($lowerKey, 'content')
            ) {
                $redacted[$key] = '[REDACTED]';
            } else {
                $redacted[$key] = $value;
            }
        }

        $entry = json_encode([
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'event' => $event,
            'context' => $redacted,
        ], JSON_UNESCAPED_SLASHES);

        if ($entry === false) {
            return;
        }

        $file = $this->logDirectory . '/docvera-' . gmdate('Y-m-d') . '.log';
        @file_put_contents($file, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

