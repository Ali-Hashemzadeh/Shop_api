<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Carbon\Carbon;
use Modules\Blog\Domain\Models\BlogTag;

/**
 * A flat Blog tag (no description by design).
 */
final class BlogTagDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly bool $isActive,
        public readonly Carbon $createdAt,
        public readonly Carbon $updatedAt,
    ) {}

    public static function fromModel(BlogTag $tag): self
    {
        return new self(
            id: (int) $tag->id,
            name: (string) $tag->name,
            slug: (string) $tag->slug,
            isActive: (bool) $tag->is_active,
            createdAt: Carbon::parse($tag->created_at),
            updatedAt: Carbon::parse($tag->updated_at),
        );
    }
}
