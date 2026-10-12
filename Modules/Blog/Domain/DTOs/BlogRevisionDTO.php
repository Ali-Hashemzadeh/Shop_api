<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Carbon\Carbon;
use Modules\Blog\Domain\Models\BlogPostRevision;

/**
 * One immutable article revision. `content` is null on list rows.
 */
final class BlogRevisionDTO
{
    /**
     * @param  array<string, mixed>|null  $seoSnapshot
     * @param  array<string, mixed>|null  $taxonomySnapshot
     * @param  list<array<string, mixed>>|null  $authorSnapshot
     */
    public function __construct(
        public readonly int $id,
        public readonly int $postId,
        public readonly int $revisionNumber,
        public readonly string $title,
        public readonly string $slug,
        public readonly ?string $excerpt,
        public readonly ?string $content,
        public readonly ?int $featuredMediaId,
        public readonly string $statusSnapshot,
        public readonly ?array $seoSnapshot,
        public readonly ?array $taxonomySnapshot,
        public readonly ?array $authorSnapshot,
        public readonly ?int $createdByUserId,
        public readonly Carbon $createdAt,
    ) {}

    public static function fromModel(BlogPostRevision $revision, bool $withContent = true): self
    {
        return new self(
            id: (int) $revision->id,
            postId: (int) $revision->blog_post_id,
            revisionNumber: (int) $revision->revision_number,
            title: (string) $revision->title,
            slug: (string) $revision->slug,
            excerpt: $revision->excerpt,
            content: $withContent ? (string) $revision->content : null,
            featuredMediaId: $revision->featured_media_id,
            statusSnapshot: (string) $revision->status_snapshot,
            seoSnapshot: $revision->seo_snapshot,
            taxonomySnapshot: $revision->taxonomy_snapshot,
            authorSnapshot: $revision->author_snapshot,
            createdByUserId: $revision->created_by_user_id,
            createdAt: Carbon::parse($revision->created_at),
        );
    }
}
