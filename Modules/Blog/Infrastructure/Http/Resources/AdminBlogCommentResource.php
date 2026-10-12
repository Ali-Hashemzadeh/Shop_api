<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogCommentDTO;

/** Moderation shape. @mixin BlogCommentDTO */
class AdminBlogCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogCommentDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'public_code' => $dto->publicCode,
            'post_id' => $dto->postId,
            'post_public_code' => $dto->postPublicCode,
            'user_id' => $dto->userId,
            'author_name' => $dto->authorName,
            'parent_id' => $dto->parentId,
            'parent_public_code' => $dto->parentPublicCode,
            'body' => $dto->body,
            'status' => $dto->status,
            'is_deleted' => $dto->isDeleted,
            'open_reports_count' => $dto->openReportsCount,
            'attachments' => BlogMediaResource::collection($dto->attachments),
            'reactions' => $dto->reactionCounts,
            'edited_at' => $dto->editedAt?->toISOString(),
            'approved_at' => $dto->approvedAt?->toISOString(),
            'created_at' => $dto->createdAt->toISOString(),
        ];
    }
}
