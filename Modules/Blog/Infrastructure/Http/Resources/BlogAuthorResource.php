<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Blog\Domain\DTOs\BlogAuthorDTO;
use Modules\Blog\Infrastructure\Support\BlogUrls;

/**
 * Public author profile. Never exposes the linked Identity `user_id` — see
 * AdminBlogAuthorResource for the back-office shape.
 *
 * @mixin BlogAuthorDTO
 */
class BlogAuthorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogAuthorDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'display_name' => $dto->displayName,
            'slug' => $dto->slug,
            'url' => BlogUrls::author($dto->slug),
            'bio' => $dto->bio,
            'avatar' => $dto->avatar === null ? null : new BlogMediaResource($dto->avatar),
            'job_title' => $dto->jobTitle,
            'role' => $this->when($dto->role !== null, $dto->role),
            'seo' => [
                'title' => $dto->seoTitle ?: $dto->displayName,
                'description' => $dto->seoDescription ?: $dto->bio,
                'canonical_url' => BlogUrls::author($dto->slug),
            ],
        ];
    }
}
