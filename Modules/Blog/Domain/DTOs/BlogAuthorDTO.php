<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Carbon\Carbon;
use Modules\Blog\Domain\Models\BlogAuthor;

/**
 * A Blog author profile. `userId` is carried for admin surfaces only — public
 * resources never render it. `role`/`sortOrder` are set when the author is
 * rendered as a contributor of a specific post.
 */
final class BlogAuthorDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $userId,
        public readonly string $displayName,
        public readonly string $slug,
        public readonly ?string $bio,
        public readonly ?BlogMediaDTO $avatar,
        public readonly ?string $jobTitle,
        public readonly bool $isActive,
        public readonly ?string $seoTitle,
        public readonly ?string $seoDescription,
        public readonly Carbon $createdAt,
        public readonly Carbon $updatedAt,
        public readonly ?string $role = null,
        public readonly ?int $sortOrder = null,
    ) {}

    public static function fromModel(
        BlogAuthor $author,
        ?BlogMediaDTO $avatar = null,
        ?string $role = null,
        ?int $sortOrder = null,
    ): self {
        return new self(
            id: (int) $author->id,
            userId: $author->user_id !== null ? (int) $author->user_id : null,
            displayName: (string) $author->display_name,
            slug: (string) $author->slug,
            bio: $author->bio,
            avatar: $avatar,
            jobTitle: $author->job_title,
            isActive: (bool) $author->is_active,
            seoTitle: $author->seo_title,
            seoDescription: $author->seo_description,
            createdAt: Carbon::parse($author->created_at),
            updatedAt: Carbon::parse($author->updated_at),
            role: $role,
            sortOrder: $sortOrder,
        );
    }
}
