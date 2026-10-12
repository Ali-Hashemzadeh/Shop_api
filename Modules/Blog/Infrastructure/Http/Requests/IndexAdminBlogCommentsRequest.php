<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Blog\Domain\Enums\BlogCommentStatus;

/** Moderation queue (`blog.comment.view-admin`). */
class IndexAdminBlogCommentsRequest extends BlogPermissionRequest
{
    protected function permissions(): array
    {
        return ['blog.comment.view-admin'];
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(BlogCommentStatus::values())],
            'post_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'reported' => ['sometimes', 'nullable', 'boolean'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'trashed' => ['sometimes', 'nullable', 'string', Rule::in(['with', 'only'])],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(['newest', 'oldest', 'most_reported'])],
            ...$this->paginationRules(),
        ];
    }
}
