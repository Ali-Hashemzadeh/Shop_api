<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Carbon\Carbon;
use Modules\Blog\Domain\Models\BlogPostActivity;

/**
 * One editorial audit-trail entry.
 */
final class BlogActivityDTO
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public readonly int $id,
        public readonly string $action,
        public readonly ?int $actorUserId,
        public readonly ?array $metadata,
        public readonly Carbon $createdAt,
    ) {}

    public static function fromModel(BlogPostActivity $activity): self
    {
        return new self(
            id: (int) $activity->id,
            action: (string) $activity->action,
            actorUserId: $activity->actor_user_id,
            metadata: $activity->metadata,
            createdAt: Carbon::parse($activity->created_at),
        );
    }
}
