<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogCommentDTO;
use Modules\Blog\Domain\DTOs\BlogCommentThreadDTO;

/**
 * Public comment shape (accepts a BlogCommentThreadDTO for nested threads or a
 * bare BlogCommentDTO). Authors appear by display name only — never a user id,
 * phone, or email. `body` is plain text: render it escaped. A deleted comment
 * kept as a thread placeholder has `is_deleted: true` and null body/author.
 * `status` is shown only to the comment's own author (e.g. "pending review").
 *
 * @mixin BlogCommentThreadDTO|BlogCommentDTO
 */
class BlogCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $thread = $this->resource instanceof BlogCommentThreadDTO ? $this->resource : null;
        /** @var BlogCommentDTO $dto */
        $dto = $thread?->comment ?? $this->resource;

        return [
            'id' => $dto->publicCode,
            'parent_id' => $dto->parentPublicCode,
            'author' => $dto->authorName === null ? null : ['name' => $dto->authorName],
            'body' => $dto->body,
            'is_deleted' => $dto->isDeleted,
            'is_edited' => $dto->editedAt !== null,
            'is_mine' => $dto->isMine,
            'status' => $this->when($dto->isMine, $dto->status),
            'attachments' => BlogMediaResource::collection($dto->attachments),
            'reactions' => $dto->reactionCounts,
            'my_reaction' => $dto->myReaction,
            'created_at' => $dto->createdAt->toISOString(),
            'edited_at' => $dto->editedAt?->toISOString(),
            'replies' => $this->when($thread !== null, fn () => self::collection($thread?->replies ?? [])),
        ];
    }
}
