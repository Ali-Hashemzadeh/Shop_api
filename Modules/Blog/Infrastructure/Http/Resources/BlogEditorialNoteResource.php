<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogEditorialNoteDTO;

/** Private editorial note — admin surfaces only. @mixin BlogEditorialNoteDTO */
class BlogEditorialNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogEditorialNoteDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'post_id' => $dto->postId,
            'user_id' => $dto->userId,
            'body' => $dto->body,
            'is_resolved' => $dto->resolvedAt !== null,
            'resolved_at' => $dto->resolvedAt?->toISOString(),
            'created_at' => $dto->createdAt->toISOString(),
            'updated_at' => $dto->updatedAt->toISOString(),
        ];
    }
}
