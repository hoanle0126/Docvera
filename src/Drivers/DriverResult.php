<?php

declare(strict_types=1);

namespace Docvera\Drivers;

final class DriverResult
{
    /**
     * @param array<string, scalar|array<string, scalar|null>|null> $summary
     */
    public function __construct(
        public readonly string $outputPath,
        public readonly string $outputFilename,
        public readonly string $outputMimeType,
        public readonly int $outputBytes,
        public readonly string $driverName,
        public readonly array $summary = []
    ) {
    }
}

