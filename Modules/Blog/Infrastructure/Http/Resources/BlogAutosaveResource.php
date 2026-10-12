<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogAutosaveDTO;

/** @mixin BlogAutosaveDTO */
class BlogAutosaveResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogAutosaveDTO $dto */
        $dto = $this->resource;

        return [
            'post_id' => $dto->postId,
            'title' => $dto->title,
            'excerpt' => $dto->excerpt,
            'content' => $dto->content,
            'base_version' => $dto->baseVersion,
            'is_stale' => $dto->isStale,
            'saved_at' => $dto->savedAt->toISOString(),
        ];
    }
}
