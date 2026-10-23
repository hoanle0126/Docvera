<?php

declare(strict_types=1);

namespace Docvera\Tools;

/**
 * Structured metadata definition for a Docvera document processing tool.
 */
final class ToolDefinition
{
    /**
     * @param list<string> $supportedMimes
     * @param list<string> $supportedExtensions
     * @param list<string> $candidateDrivers
     * @param list<OptionField> $options
     * @param list<string> $allowedPlans
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly string $category,
        public readonly string $priorityGroup,
        public readonly array $supportedMimes,
        public readonly array $supportedExtensions,
        public readonly int $minFiles,
        public readonly int $maxFiles,
        public readonly string $outputMime,
        public readonly string $outputExtensionLabel,
        public readonly array $candidateDrivers,
        public readonly array $options,
        public readonly string $ctaLabel,
        public readonly bool $guestEligible = true,
        public readonly bool $queueRequired = false,
        public readonly int $timeoutSeconds = 60,
        public readonly string $tempFilePolicy = 'isolated_job_workspace_auto_purge',
        public readonly int $retentionMinutes = 60,
        public readonly bool $apiEligible = true,
        public readonly bool $supportsPagePreview = false,
        public readonly AvailabilityResult $availability = new AvailabilityResult(true, 'ghostscript'),
        public readonly bool $enabled = true,
        public readonly int $maxFileSizeMb = 0,
        public readonly array $allowedPlans = [],
        public readonly string $apiRoutePath = '',
        public readonly ?string $seoTitle = null,
        public readonly ?string $seoDescription = null,
        public readonly ?string $seoCanonical = null,
        public readonly bool $seoIndexable = true,
        public readonly bool $seoInSitemap = true
    ) {
    }

    /**
     * @param array<string, mixed>|null $override
     */
    public function withState(AvailabilityResult $availability, ?array $override = null, string $apiRoutePath = ''): self
    {
        $enabled = $override !== null && array_key_exists('is_enabled', $override)
            ? (bool) $override['is_enabled']
            : $this->enabled;

        $guestEligible = $override !== null && array_key_exists('guest_allowed', $override)
            ? (bool) $override['guest_allowed']
            : $this->guestEligible;

        $apiEligible = $override !== null && array_key_exists('api_enabled', $override)
            ? (bool) $override['api_enabled']
            : $this->apiEligible;

        $maxFiles = $override !== null && !empty($override['max_files']) && (int) $override['max_files'] > 0
            ? max($this->minFiles, (int) $override['max_files'])
            : $this->maxFiles;

        $maxFileSizeMb = $override !== null && !empty($override['max_file_size_mb']) && (int) $override['max_file_size_mb'] > 0
            ? (int) $override['max_file_size_mb']
            : $this->maxFileSizeMb;

        $allowedPlans = $override !== null && isset($override['allowed_plans']) && is_array($override['allowed_plans'])
            ? array_values(array_map('strval', $override['allowed_plans']))
            : $this->allowedPlans;

        $options = $this->options;
        if ($override !== null && isset($override['default_options']) && is_array($override['default_options']) && $override['default_options'] !== []) {
            $defaultOverrides = $override['default_options'];
            $updatedOptions = [];
            foreach ($options as $opt) {
                if (array_key_exists($opt->name, $defaultOverrides)) {
                    $updatedOptions[] = new OptionField(
                        name: $opt->name,
                        label: $opt->label,
                        type: $opt->type,
                        default: $defaultOverrides[$opt->name],
                        required: $opt->required,
                        choices: $opt->choices,
                        min: $opt->min,
                        max: $opt->max,
                        placeholder: $opt->placeholder,
                        helpText: $opt->helpText
                    );
                } else {
                    $updatedOptions[] = $opt;
                }
            }
            $options = $updatedOptions;
        }

        if (!$enabled) {
            $availability = new AvailabilityResult(
                available: false,
                activeDriver: null,
                missingCapabilities: $availability->missingCapabilities,
                unavailableReason: 'This tool is currently disabled by the system administrator.'
            );
        }

        return new self(
            id: $this->id,
            name: $this->name,
            description: $this->description,
            category: $this->category,
            priorityGroup: $this->priorityGroup,
            supportedMimes: $this->supportedMimes,
            supportedExtensions: $this->supportedExtensions,
            minFiles: $this->minFiles,
            maxFiles: $maxFiles,
            outputMime: $this->outputMime,
            outputExtensionLabel: $this->outputExtensionLabel,
            candidateDrivers: $this->candidateDrivers,
            options: $options,
            ctaLabel: $this->ctaLabel,
            guestEligible: $guestEligible,
            queueRequired: $this->queueRequired,
            timeoutSeconds: $this->timeoutSeconds,
            tempFilePolicy: $this->tempFilePolicy,
            retentionMinutes: $this->retentionMinutes,
            apiEligible: $apiEligible,
            supportsPagePreview: $this->supportsPagePreview,
            availability: $availability,
            enabled: $enabled,
            maxFileSizeMb: $maxFileSizeMb,
            allowedPlans: $allowedPlans,
            apiRoutePath: $apiRoutePath !== '' ? $apiRoutePath : $this->apiRoutePath,
            seoTitle: $override !== null && isset($override['seo_title']) ? (string) $override['seo_title'] : $this->seoTitle,
            seoDescription: $override !== null && isset($override['seo_description']) ? (string) $override['seo_description'] : $this->seoDescription,
            seoCanonical: $override !== null && isset($override['seo_canonical']) ? (string) $override['seo_canonical'] : $this->seoCanonical,
            seoIndexable: $override !== null && array_key_exists('seo_indexable', $override) ? (bool) $override['seo_indexable'] : $this->seoIndexable,
            seoInSitemap: $override !== null && array_key_exists('seo_in_sitemap', $override) ? (bool) $override['seo_in_sitemap'] : $this->seoInSitemap
        );
    }

    public function withAvailability(AvailabilityResult $availability): self
    {
        return $this->withState($availability);
    }

    public function acceptsMime(string $mime): bool
    {
        return in_array($mime, $this->supportedMimes, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'priority_group' => $this->priorityGroup,
            'supported_mimes' => $this->supportedMimes,
            'supported_extensions' => $this->supportedExtensions,
            'min_files' => $this->minFiles,
            'max_files' => $this->maxFiles,
            'max_file_size_mb' => $this->maxFileSizeMb,
            'output_mime' => $this->outputMime,
            'output_extension_label' => $this->outputExtensionLabel,
            'candidate_drivers' => $this->candidateDrivers,
            'options' => array_map(static fn(OptionField $o): array => $o->toArray(), $this->options),
            'cta_label' => $this->ctaLabel,
            'enabled' => $this->enabled,
            'guest_eligible' => $this->guestEligible,
            'allowed_plans' => $this->allowedPlans,
            'queue_required' => $this->queueRequired,
            'timeout_seconds' => $this->timeoutSeconds,
            'temp_file_policy' => $this->tempFilePolicy,
            'retention_minutes' => $this->retentionMinutes,
            'api_eligible' => $this->apiEligible,
            'api_endpoint' => $this->apiRoutePath !== '' ? $this->apiRoutePath : ('/api/v1/tools/' . $this->id),
            'supports_page_preview' => $this->supportsPagePreview,
            'availability' => $this->availability->toArray(),
            'seo' => [
                'title' => $this->seoTitle,
                'description' => $this->seoDescription,
                'canonical' => $this->seoCanonical,
                'indexable' => $this->seoIndexable,
                'in_sitemap' => $this->seoInSitemap,
            ],
        ];
    }
}
