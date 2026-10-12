<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Blog\Domain\Enums\BlogCommentReportReason;
use Modules\Blog\Domain\Enums\BlogCommentReportStatus;
use Modules\Blog\Domain\Models\BlogComment;
use Modules\Blog\Domain\Models\BlogCommentReport;

/**
 * A reader reports a comment. Idempotent per reader: while their report on this
 * comment is still open, reporting again returns that same report instead of
 * stacking duplicates. Once a moderator closes it, a new report may be filed.
 *
 * @return array{report: BlogCommentReport, created: bool}
 */
final class ReportBlogCommentAction
{
    /** @return array{report: BlogCommentReport, created: bool} */
    public function handle(BlogComment $comment, int $userId, BlogCommentReportReason $reason, ?string $details): array
    {
        return DB::transaction(function () use ($comment, $userId, $reason, $details): array {
            $existing = BlogCommentReport::query()
                ->where('comment_id', $comment->id)
                ->where('reported_by_user_id', $userId)
                ->where('status', BlogCommentReportStatus::Open->value)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return ['report' => $existing, 'created' => false];
            }

            $report = BlogCommentReport::query()->create([
                'comment_id' => $comment->id,
                'reported_by_user_id' => $userId,
                'reason' => $reason->value,
                'details' => $details,
                'status' => BlogCommentReportStatus::Open->value,
            ]);

            return ['report' => $report, 'created' => true];
        });
    }
}
