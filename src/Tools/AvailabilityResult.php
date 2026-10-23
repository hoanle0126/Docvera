<?php

declare(strict_types=1);

namespace Docvera\Tools;

final class AvailabilityResult
{
    /**
     * @param list<string> $missingCapabilities
     */
    public function __construct(
        public readonly bool $available,
        public readonly ?string $activeDriver,
        public readonly array $missingCapabilities = [],
        public readonly ?string $unavailableReason = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'active_driver' => $this->activeDriver,
            'missing_capabilities' => $this->missingCapabilities,
            'unavailable_reason' => $this->unavailableReason,
        ];
    }
}

