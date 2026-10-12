<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Blog\Domain\Enums\BlogCommentStatus;

/**
 * Close a report as `actioned` (`blog.comment.moderate`): the comment is
 * rejected by default, or marked spam.
 */
class ActionBlogCommentReportRequest extends ModerateBlogCommentsRequest
{
    public function rules(): array
    {
        return [
            'comment_status' => ['sometimes', 'nullable', 'string', Rule::in([BlogCommentStatus::Rejected->value, BlogCommentStatus::Spam->value])],
        ];
    }
}
