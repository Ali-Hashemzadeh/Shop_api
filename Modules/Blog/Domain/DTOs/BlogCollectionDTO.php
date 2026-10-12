<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Carbon\Carbon;

/**
 * A curated editorial collection. Public responses carry `posts` (published
 * only, in curated order); admin responses carry every curated `postIds`.
 */
final class BlogCollectionDTO
{
    /**
     * @param  list<BlogPostSummaryDTO>|null  $posts
     * @param  list<int>|null  $postIds
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly bool $isActive,
        public readonly ?Carbon $startsAt,
        public readonly ?Carbon $endsAt,
        public readonly Carbon $createdAt,
        public readonly Carbon $updatedAt,
        public readonly ?array $posts = null,
        public readonly ?array $postIds = null,
    ) {}
}
