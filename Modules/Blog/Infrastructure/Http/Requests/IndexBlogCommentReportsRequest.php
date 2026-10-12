<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Blog\Domain\Enums\BlogCommentReportReason;
use Modules\Blog\Domain\Enums\BlogCommentReportStatus;

/** Report queue (`blog.comment.moderate`). */
class IndexBlogCommentReportsRequest extends ModerateBlogCommentsRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(BlogCommentReportStatus::values())],
            'reason' => ['sometimes', 'nullable', 'string', Rule::in(BlogCommentReportReason::values())],
            'comment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ...$this->paginationRules(),
        ];
    }
}
