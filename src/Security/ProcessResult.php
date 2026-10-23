<?php

declare(strict_types=1);

namespace Docvera\Security;

final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut,
        public readonly float $durationMs
    ) {
    }

    public function isSuccessful(): bool
    {
        return !$this->timedOut && $this->exitCode === 0;
    }
}

