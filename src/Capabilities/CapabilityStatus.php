<?php

declare(strict_types=1);

namespace Docvera\Capabilities;

final class CapabilityStatus
{
    public readonly string $name;

    /**
     * @param array<string, scalar|null> $meta
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $category,
        public readonly bool $available,
        public readonly bool $required,
        public readonly ?string $version,
        public readonly ?string $binaryPath,
        public readonly string $description,
        public readonly ?string $missingReason = null,
        public readonly array $meta = []
    ) {
        $this->name = $label;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'category' => $this->category,
            'available' => $this->available,
            'required' => $this->required,
            'version' => $this->version,
            'description' => $this->description,
            'missing_reason' => $this->missingReason,
            'meta' => $this->meta,
        ];
    }
}

