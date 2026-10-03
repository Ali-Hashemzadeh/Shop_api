<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\DTOs;

/**
 * One review draft produced by AI stage 2, before it is persisted. Ratings are
 * chosen by the AI (not forced to 5), names are random Persian personas.
 */
class GeneratedReviewDraftDTO
{
    public function __construct(
        public readonly string $name,
        public readonly int $rating,
        public readonly ?string $title,
        public readonly string $body,
    ) {}
}
