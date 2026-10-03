<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\DTOs;

use Carbon\Carbon;
use Modules\ProductReviewAI\Domain\Models\AiGeneratedReview;

/**
 * Immutable view of one AI-generated review draft.
 */
class AiGeneratedReviewDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $generationId,
        public readonly int $productId,
        public readonly string $name,
        public readonly int $rating,
        public readonly ?string $title,
        public readonly string $body,
        public readonly string $status,
        public readonly ?int $approvedBy,
        public readonly ?Carbon $approvedAt,
        public readonly ?int $publishedReviewId,
        public readonly Carbon $createdAt,
    ) {}

    public static function fromModel(AiGeneratedReview $r): self
    {
        return new self(
            id: (int) $r->id,
            generationId: (int) $r->generation_id,
            productId: (int) $r->product_id,
            name: (string) $r->name,
            rating: (int) $r->rating,
            title: $r->title,
            body: (string) $r->body,
            status: (string) ($r->status instanceof \BackedEnum ? $r->status->value : $r->status),
            approvedBy: $r->approved_by !== null ? (int) $r->approved_by : null,
            approvedAt: $r->approved_at !== null ? Carbon::parse($r->approved_at) : null,
            publishedReviewId: $r->published_review_id !== null ? (int) $r->published_review_id : null,
            createdAt: Carbon::parse($r->created_at),
        );
    }
}
