<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogMediaDTO;

/** @mixin BlogMediaDTO */
class BlogMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogMediaDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'url' => $dto->url,
            'mime_type' => $dto->mimeType,
            'alt_text' => $dto->altText,
            'caption' => $dto->caption,
            'role' => $dto->role,
            'sort_order' => $dto->sortOrder,
        ];
    }
}
