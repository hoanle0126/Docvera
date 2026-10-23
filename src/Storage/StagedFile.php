<?php

declare(strict_types=1);

namespace Docvera\Storage;

final class StagedFile
{
    public function __construct(
        public readonly string $internalPath,
        public readonly string $internalFilename,
        public readonly string $originalSafeName,
        public readonly string $mimeType,
        public readonly string $extension,
        public readonly int $sizeBytes
    ) {
    }
}

