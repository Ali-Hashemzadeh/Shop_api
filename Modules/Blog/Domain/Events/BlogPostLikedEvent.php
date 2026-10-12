<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a reader liked a post. Raised only on a real
 * state change — repeating an idempotent like raises nothing.
 */
final class BlogPostLikedEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $postId,
        public readonly int $userId,
        public readonly string $occurredAt,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
