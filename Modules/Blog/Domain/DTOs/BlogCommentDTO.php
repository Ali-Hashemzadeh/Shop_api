<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Carbon\Carbon;

/**
 * One Blog comment. A soft-deleted comment kept only as a thread placeholder
 * has `isDeleted=true` and a null body/author. `userId`, `status`, and
 * `openReportsCount` are for admin surfaces; the public resource never renders
 * them (authors appear by display name only).
 */
final class BlogCommentDTO
{
    /**
     * @param  list<BlogMediaDTO>  $attachments
     * @param  array<string, int>  $reactionCounts
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicCode,
        public readonly int $postId,
        public readonly ?string $postPublicCode,
        public readonly int $userId,
        public readonly ?string $authorName,
        public readonly ?int $parentId,
        public readonly ?string $parentPublicCode,
        public readonly ?string $body,
        public readonly string $status,
        public readonly bool $isDeleted,
        public readonly ?Carbon $editedAt,
        public readonly ?Carbon $approvedAt,
        public readonly Carbon $createdAt,
        public readonly array $attachments,
        public readonly array $reactionCounts,
        public readonly ?string $myReaction,
        public readonly int $openReportsCount = 0,
        public readonly bool $isMine = false,
    ) {}
}
