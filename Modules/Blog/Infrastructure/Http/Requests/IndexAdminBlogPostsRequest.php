<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Blog\Domain\Enums\BlogPostStatus;

/** Admin post listing with editorial filters (`blog.view-admin`). */
class IndexAdminBlogPostsRequest extends ViewBlogAdminRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(BlogPostStatus::values())],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'tag_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'author_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'featured' => ['sometimes', 'nullable', 'boolean'],
            'published_from' => ['sometimes', 'nullable', 'date'],
            'published_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:published_from'],
            'created_from' => ['sometimes', 'nullable', 'date'],
            'created_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:created_from'],
            'trashed' => ['sometimes', 'nullable', 'string', Rule::in(['with', 'only'])],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(['newest', 'oldest', 'updated', 'title'])],
            ...$this->paginationRules(),
        ];
    }
}
