<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a reply became visible (it was approved) under
 * someone's comment. Raised at approval rather than submission so the parent
 * author is never alerted about a reply that moderation later rejects.
 */
final class BlogCommentRepliedEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $replyCommentId,
        public readonly string $replyPublicCode,
        public readonly int $parentCommentId,
        public readonly string $parentPublicCode,
        public readonly int $parentAuthorUserId,
        public readonly int $replyAuthorUserId,
        public readonly int $postId,
        public readonly string $postSlug,
        public readonly string $postTitle,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
