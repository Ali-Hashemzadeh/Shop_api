<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Carbon\Carbon;
use Modules\Blog\Domain\Models\BlogEditorialNote;

/**
 * A private editorial note. Only ever rendered on admin surfaces.
 */
final class BlogEditorialNoteDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $postId,
        public readonly int $userId,
        public readonly string $body,
        public readonly ?Carbon $resolvedAt,
        public readonly Carbon $createdAt,
        public readonly Carbon $updatedAt,
    ) {}

    public static function fromModel(BlogEditorialNote $note): self
    {
        return new self(
            id: (int) $note->id,
            postId: (int) $note->blog_post_id,
            userId: (int) $note->user_id,
            body: (string) $note->body,
            resolvedAt: $note->resolved_at !== null ? Carbon::parse($note->resolved_at) : null,
            createdAt: Carbon::parse($note->created_at),
            updatedAt: Carbon::parse($note->updated_at),
        );
    }
}
