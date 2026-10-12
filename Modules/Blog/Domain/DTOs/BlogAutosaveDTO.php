<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Carbon\Carbon;
use Modules\Blog\Domain\Models\BlogPostAutosave;

/**
 * An editor's recoverable autosave. `isStale` is true when the post has been
 * saved (version bumped) since this autosave was taken.
 */
final class BlogAutosaveDTO
{
    public function __construct(
        public readonly int $postId,
        public readonly int $userId,
        public readonly ?string $title,
        public readonly ?string $excerpt,
        public readonly ?string $content,
        public readonly int $baseVersion,
        public readonly bool $isStale,
        public readonly Carbon $savedAt,
    ) {}

    public static function fromModel(BlogPostAutosave $autosave, int $currentVersion): self
    {
        return new self(
            postId: (int) $autosave->blog_post_id,
            userId: (int) $autosave->user_id,
            title: $autosave->title,
            excerpt: $autosave->excerpt,
            content: $autosave->content,
            baseVersion: (int) $autosave->base_version,
            isStale: (int) $autosave->base_version !== $currentVersion,
            savedAt: Carbon::parse($autosave->updated_at),
        );
    }
}
