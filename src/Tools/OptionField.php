<?php

declare(strict_types=1);

namespace Docvera\Tools;

final class OptionField
{
    /**
     * @param array<string, string> $choices
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly mixed $default = null,
        public readonly bool $required = false,
        public readonly ?string $placeholder = null,
        public readonly ?string $helpText = null,
        public readonly array $choices = [],
        public readonly ?int $min = null,
        public readonly ?int $max = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'default' => $this->default,
            'required' => $this->required,
            'placeholder' => $this->placeholder,
            'help_text' => $this->helpText,
            'choices' => $this->choices,
            'min' => $this->min,
            'max' => $this->max,
        ];
    }
}

