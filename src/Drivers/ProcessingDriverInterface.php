<?php

declare(strict_types=1);

namespace Docvera\Drivers;

use Docvera\Storage\StagedFile;

interface ProcessingDriverInterface
{
    public function getName(): string;

    public function isAvailable(): bool;

    /**
     * @param list<StagedFile> $inputs
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    public function process(
        string $toolId,
        array $inputs,
        array $options,
        array $workspace
    ): DriverResult;
}

