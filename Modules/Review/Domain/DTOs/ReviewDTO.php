<?php

declare(strict_types=1);

namespace Modules\Review\Domain\DTOs;

use Carbon\Carbon;
use Modules\Review\Domain\Enums\ReviewStatus;
use Modules\Review\Domain\Enums\ReviewSubjectType;
use Modules\Review\Domain\Models\Review;

/**
 * Immutable view of one review crossing the module boundary.
 *
 * `galleryUrls` are resolved public URLs (batch-hydrated through
 * MediaManagerInterface by the manager) — raw media ids never cross the wall.
 */
class ReviewDTO
{
    /**
     * @param  list<string>  $galleryUrls
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly int $userId,
        public readonly ?int $rating,
        public readonly string $body,
        public readonly array $galleryUrls,
        public readonly bool $verifiedPurchase,
        public readonly string $status,
        public readonly ?string $sellerReply,
        public readonly ?Carbon $sellerReplyAt,
        public readonly Carbon $createdAt,
    ) {}

    /**
     * @param  list<string>  $galleryUrls
     */
    public static function fromModel(Review $review, array $galleryUrls = []): self
    {
        return new self(
            id: $review->id,
            uuid: (string) $review->uuid,
            subjectType: $review->subject_type instanceof ReviewSubjectType
                ? $review->subject_type->value
                : (string) $review->subject_type,
            subjectId: (int) $review->subject_id,
            userId: (int) $review->user_id,
            rating: $review->rating,
            body: (string) $review->body,
            galleryUrls: $galleryUrls,
            verifiedPurchase: (bool) $review->verified_purchase,
            status: $review->status instanceof ReviewStatus
                ? $review->status->value
                : (string) $review->status,
            sellerReply: $review->seller_reply,
            sellerReplyAt: $review->seller_reply_at !== null
                ? Carbon::parse($review->seller_reply_at)
                : null,
            createdAt: Carbon::parse($review->created_at),
        );
    }
}
