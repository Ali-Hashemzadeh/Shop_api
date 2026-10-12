<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Carbon\Carbon;

/**
 * One article in a keyed editorial slot. `post` is hydrated on public reads
 * (published posts only); admin rows carry the raw `postId`.
 */
final class BlogPlacementDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $placementKey,
        public readonly int $postId,
        public readonly ?Carbon $startsAt,
        public readonly ?Carbon $endsAt,
        public readonly int $sortOrder,
        public readonly bool $isActiveNow,
        public readonly ?BlogPostSummaryDTO $post = null,
    ) {}
}
