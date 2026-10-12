<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a reader wrote a comment or reply (it starts in
 * moderation). Consumers that count *visible* comments should use
 * BlogCommentApprovedEvent instead.
 */
final class BlogCommentCreatedEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $commentId,
        public readonly string $commentPublicCode,
        public readonly int $postId,
        public readonly string $postPublicCode,
        public readonly int $userId,
        public readonly ?int $parentCommentId,
        public readonly string $occurredAt,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
