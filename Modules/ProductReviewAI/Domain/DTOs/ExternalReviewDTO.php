<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\DTOs;

/**
 * One customer review collected from an external marketplace. `raw` preserves
 * the untouched source payload for auditability.
 */
class ExternalReviewDTO
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?int $rating,
        public readonly ?string $title,
        public readonly string $body,
        public readonly array $raw = [],
    ) {}
}
