<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Carbon\Carbon;
use Modules\Blog\Domain\Models\BlogCommentReport;

/**
 * A reader's report against a comment.
 */
final class BlogCommentReportDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $commentId,
        public readonly ?string $commentPublicCode,
        public readonly ?string $commentStatus,
        public readonly int $reportedByUserId,
        public readonly string $reason,
        public readonly ?string $details,
        public readonly string $status,
        public readonly ?int $reviewedByUserId,
        public readonly ?Carbon $reviewedAt,
        public readonly Carbon $createdAt,
    ) {}

    public static function fromModel(BlogCommentReport $report): self
    {
        $comment = $report->relationLoaded('comment') ? $report->comment : null;

        return new self(
            id: (int) $report->id,
            commentId: (int) $report->comment_id,
            commentPublicCode: $comment?->public_code,
            commentStatus: $comment?->status?->value,
            reportedByUserId: (int) $report->reported_by_user_id,
            reason: $report->reason->value,
            details: $report->details,
            status: $report->status->value,
            reviewedByUserId: $report->reviewed_by_user_id,
            reviewedAt: $report->reviewed_at !== null ? Carbon::parse($report->reviewed_at) : null,
            createdAt: Carbon::parse($report->created_at),
        );
    }
}
