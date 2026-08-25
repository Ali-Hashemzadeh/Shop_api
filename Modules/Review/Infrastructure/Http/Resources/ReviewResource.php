<?php

declare(strict_types=1);

namespace Modules\Review\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Review\Domain\DTOs\ReviewDTO;

/**
 * Customer-facing shape of a review. Accepts the DTO only, never the model.
 *
 * @mixin ReviewDTO
 */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ReviewDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->uuid,
            'subject_type' => $dto->subjectType,
            'subject_id' => $dto->subjectId,
            'rating' => $dto->rating,
            'body' => $dto->body,
            'gallery_urls' => $dto->galleryUrls,
            'verified_purchase' => $dto->verifiedPurchase,
            'status' => $dto->status,
            'seller_reply' => $dto->sellerReply,
            'seller_reply_at' => $dto->sellerReplyAt?->toISOString(),
            'created_at' => $dto->createdAt->toISOString(),
        ];
    }
}
