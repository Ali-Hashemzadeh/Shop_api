<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogCommentReportDTO;

/**
 * The reporter's view of their own report — no moderator or user ids.
 *
 * @mixin BlogCommentReportDTO
 */
class BlogCommentReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogCommentReportDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'comment_public_code' => $dto->commentPublicCode,
            'reason' => $dto->reason,
            'details' => $dto->details,
            'status' => $dto->status,
            'reviewed_at' => $dto->reviewedAt?->toISOString(),
            'created_at' => $dto->createdAt->toISOString(),
        ];
    }
}
