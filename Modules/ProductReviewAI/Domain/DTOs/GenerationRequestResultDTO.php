<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\DTOs;

/**
 * Result of requesting a generation.
 *
 * When the product already has published AI reviews and the caller has not
 * confirmed, no run is started: `warning` is true and `existingAiReviews` tells
 * the frontend how many exist so it can ask for confirmation. Otherwise a run is
 * queued and `generation` carries it.
 */
class GenerationRequestResultDTO
{
    public function __construct(
        public readonly bool $warning,
        public readonly int $existingAiReviews,
        public readonly ?AiReviewGenerationDTO $generation,
    ) {}
}
