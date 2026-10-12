<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a post became publicly visible (manual publish
 * or the scheduler). Primitives only. Carries the taxonomy/author ids so the
 * Notification module can find followers through BlogManagerInterface without
 * reading Blog tables. `isFirstPublication` lets consumers notify followers once
 * rather than on every republish.
 */
final class BlogPostPublishedEvent
{
    public readonly string $eventId;

    /**
     * @param  list<int>  $categoryIds
     * @param  list<int>  $tagIds
     * @param  list<int>  $authorIds
     */
    public function __construct(
        public readonly int $postId,
        public readonly string $postPublicCode,
        public readonly string $slug,
        public readonly string $title,
        public readonly array $categoryIds,
        public readonly array $tagIds,
        public readonly array $authorIds,
        public readonly bool $isFirstPublication,
        public readonly string $publishedAt,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
