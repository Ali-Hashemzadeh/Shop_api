<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Blog\Domain\Enums\BlogCommentReportStatus;
use Modules\Blog\Domain\Enums\BlogCommentStatus;
use Modules\Blog\Domain\Models\BlogCommentReport;

/**
 * Close a report as `reviewed` (looked at, no change), `dismissed` (unfounded),
 * or `actioned` (the comment is moderated — rejected by default, or spam — via
 * the ordinary ModerateBlogCommentAction so counters and events stay consistent).
 */
final class ReviewBlogCommentReportAction
{
    public function __construct(
        private readonly ModerateBlogCommentAction $moderate,
    ) {}

    public function handle(
        BlogCommentReport $report,
        BlogCommentReportStatus $outcome,
        int $moderatorUserId,
        ?BlogCommentStatus $commentStatus = null,
    ): BlogCommentReport {
        return DB::transaction(function () use ($report, $outcome, $moderatorUserId, $commentStatus): BlogCommentReport {
            $report->status = $outcome;
            $report->reviewed_by_user_id = $moderatorUserId;
            $report->reviewed_at = now();
            $report->save();

            if ($outcome === BlogCommentReportStatus::Actioned && $report->comment !== null) {
                $this->moderate->handle($report->comment, $commentStatus ?? BlogCommentStatus::Rejected, $moderatorUserId);
            }

            return $report->fresh(['comment']);
        });
    }
}
