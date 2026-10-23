<?php

declare(strict_types=1);

namespace Docvera\Core;

final class Config
{
    /** @var array<string, mixed> */
    private array $items;

    /**
     * @param array<string, mixed> $items
     */
    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public static function fromDefaultPath(?string $basePath = null): self
    {
        $root = $basePath ?? dirname(__DIR__, 2);
        Env::load($root . '/.env');
        /** @var array<string, mixed> $data */
        $data = require $root . '/config/app.php';
        return new self($data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $current = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $current = &$this->items;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
            } else {
                if (!isset($current[$segment]) || !is_array($current[$segment])) {
                    $current[$segment] = [];
                }
                $current = &$current[$segment];
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }
}

