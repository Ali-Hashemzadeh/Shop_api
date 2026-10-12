<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogSitemapEntryDTO;

/** @mixin BlogSitemapEntryDTO */
class BlogSitemapEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogSitemapEntryDTO $dto */
        $dto = $this->resource;

        return [
            'type' => $dto->type,
            'slug' => $dto->slug,
            'url' => $dto->url,
            'published_at' => $dto->publishedAt,
            'updated_at' => $dto->updatedAt,
            'indexable' => $dto->indexable,
        ];
    }
}
