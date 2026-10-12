<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Modules\Blog\Domain\DTOs\BlogAuthorDTO;

/**
 * Back-office author profile: the public shape plus the loose Identity link,
 * active flag, raw SEO fields, and timestamps.
 *
 * @mixin BlogAuthorDTO
 */
class AdminBlogAuthorResource extends BlogAuthorResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogAuthorDTO $dto */
        $dto = $this->resource;

        return [
            ...parent::toArray($request),
            'user_id' => $dto->userId,
            'is_active' => $dto->isActive,
            'seo_title' => $dto->seoTitle,
            'seo_description' => $dto->seoDescription,
            'created_at' => $dto->createdAt->toISOString(),
            'updated_at' => $dto->updatedAt->toISOString(),
        ];
    }
}
