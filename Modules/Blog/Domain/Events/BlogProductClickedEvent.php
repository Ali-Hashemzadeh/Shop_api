<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a reader clicked a product linked from an
 * article (shoppable content). Deduplicated before dispatch. `sessionHash` is a
 * one-way hash of the guest session id — never the raw identifier.
 */
final class BlogProductClickedEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $postId,
        public readonly int $productId,
        public readonly ?int $userId,
        public readonly ?string $sessionHash,
        public readonly ?string $placement,
        public readonly string $occurredAt,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
