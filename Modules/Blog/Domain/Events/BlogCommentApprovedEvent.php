<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a comment passed moderation and is now public.
 */
final class BlogCommentApprovedEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $commentId,
        public readonly string $commentPublicCode,
        public readonly int $authorUserId,
        public readonly int $postId,
        public readonly string $postSlug,
        public readonly string $postTitle,
        public readonly bool $isReply,
        public readonly string $occurredAt,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
