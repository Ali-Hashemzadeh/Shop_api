<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Modules\Blog\Domain\DTOs\BlogCommentReportDTO;

/**
 * The moderator's view of a report: adds the comment id/status and the loose
 * reporter/reviewer user ids.
 *
 * @mixin BlogCommentReportDTO
 */
class AdminBlogCommentReportResource extends BlogCommentReportResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogCommentReportDTO $dto */
        $dto = $this->resource;

        return [
            ...parent::toArray($request),
            'comment_id' => $dto->commentId,
            'comment_status' => $dto->commentStatus,
            'reported_by_user_id' => $dto->reportedByUserId,
            'reviewed_by_user_id' => $dto->reviewedByUserId,
        ];
    }
}
