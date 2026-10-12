<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a post was archived. `wasPublic` says whether it
 * was live at the moment of archiving (so consumers can treat it as a removal).
 */
final class BlogPostArchivedEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $postId,
        public readonly string $postPublicCode,
        public readonly string $slug,
        public readonly bool $wasPublic,
        public readonly string $occurredAt,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
