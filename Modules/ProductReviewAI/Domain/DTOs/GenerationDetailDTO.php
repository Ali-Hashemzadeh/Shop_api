<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\DTOs;

/**
 * A generation run together with its produced drafts — the payload behind
 * GET /admin/ai-reviews/{generation}.
 */
class GenerationDetailDTO
{
    /**
     * @param  list<AiGeneratedReviewDTO>  $drafts
     */
    public function __construct(
        public readonly AiReviewGenerationDTO $generation,
        public readonly array $drafts,
    ) {}
}
