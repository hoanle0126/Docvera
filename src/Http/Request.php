<?php

declare(strict_types=1);

namespace Docvera\Http;

final class Request
{
    public readonly string $method;
    public readonly string $path;
    /** @var array<string, mixed> */
    public readonly array $query;
    /** @var array<string, mixed> */
    public readonly array $post;
    /** @var array<string, mixed> */
    public readonly array $files;
    /** @var array<string, mixed> */
    public readonly array $server;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function __construct(
        string $method,
        string $path,
        array $query = [],
        array $post = [],
        array $files = [],
        array $server = []
    ) {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->query = $query;
        $this->post = $post;
        $this->files = $files;
        $this->server = $server;
    }

    public static function capture(): self
    {
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $rawPath = (string) parse_url($uri, PHP_URL_PATH);
        $path = '/' . trim($rawPath, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return new self($method, $path, $_GET, $_POST, $_FILES, $_SERVER);
    }

    public function wantsJson(): bool
    {
        $accept = strtolower((string) ($this->server['HTTP_ACCEPT'] ?? ''));
        $xhr = strtolower((string) ($this->server['HTTP_X_REQUESTED_WITH'] ?? ''));
        return str_contains($accept, 'application/json') || $xhr === 'xmlhttprequest';
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }
        return $default;
    }

    /**
     * Normalizes single or multi-file $_FILES arrays into a flat list of file descriptors.
     *
     * @return list<array{path: string, original_name: string, size: int, error: int}>
     */
    public function uploadedFiles(string $field = 'files'): array
    {
        if (!isset($this->files[$field]) || !is_array($this->files[$field])) {
            return [];
        }

        $entry = $this->files[$field];
        $normalized = [];

        if (isset($entry['name']) && is_array($entry['name'])) {
            $count = count($entry['name']);
            for ($i = 0; $i < $count; $i++) {
                $err = (int) ($entry['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $normalized[] = [
                    'path' => (string) ($entry['tmp_name'][$i] ?? ''),
                    'original_name' => (string) ($entry['name'][$i] ?? 'document'),
                    'size' => (int) ($entry['size'][$i] ?? 0),
                    'error' => $err,
                ];
            }
        } elseif (isset($entry['name']) && is_string($entry['name'])) {
            $err = (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_NO_FILE) {
                $normalized[] = [
                    'path' => (string) ($entry['tmp_name'] ?? ''),
                    'original_name' => $entry['name'],
                    'size' => (int) ($entry['size'] ?? 0),
                    'error' => $err,
                ];
            }
        }

        return $normalized;
    }
}

