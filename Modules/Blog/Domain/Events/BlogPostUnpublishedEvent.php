<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a live post was taken off the storefront, either
 * manually or because its `unpublish_at` came due (`trigger` = manual|scheduled).
 */
final class BlogPostUnpublishedEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $postId,
        public readonly string $postPublicCode,
        public readonly string $slug,
        public readonly string $trigger,
        public readonly string $occurredAt,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
