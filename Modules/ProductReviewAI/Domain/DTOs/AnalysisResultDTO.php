<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\DTOs;

/**
 * Structured output of AI stage 1 (analysis of collected external reviews).
 * Stored on the generation as analysis_json and fed into stage 2 (generation).
 */
class AnalysisResultDTO
{
    /**
     * @param  list<string>  $positivePoints
     * @param  list<string>  $negativePoints
     * @param  list<string>  $customerProfiles
     * @param  list<string>  $importantFeatures
     */
    public function __construct(
        public readonly array $positivePoints,
        public readonly array $negativePoints,
        public readonly array $customerProfiles,
        public readonly array $importantFeatures,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $strings = static fn (string $key): array => array_values(array_filter(
            array_map(
                static fn ($v): string => is_string($v) ? trim($v) : (is_scalar($v) ? (string) $v : ''),
                (array) ($data[$key] ?? []),
            ),
            static fn (string $v): bool => $v !== '',
        ));

        return new self(
            positivePoints: $strings('positive_points'),
            negativePoints: $strings('negative_points'),
            customerProfiles: $strings('customer_profiles'),
            importantFeatures: $strings('important_features'),
        );
    }

    /**
     * @return array{positive_points: list<string>, negative_points: list<string>, customer_profiles: list<string>, important_features: list<string>}
     */
    public function toArray(): array
    {
        return [
            'positive_points' => $this->positivePoints,
            'negative_points' => $this->negativePoints,
            'customer_profiles' => $this->customerProfiles,
            'important_features' => $this->importantFeatures,
        ];
    }
}
