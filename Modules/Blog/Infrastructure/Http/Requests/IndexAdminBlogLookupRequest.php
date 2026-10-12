<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Admin listing of categories / tags / authors / collections / placements.
 * Anyone who works in the editorial back office (`blog.view-admin`) can read
 * these lookups to fill a post form; managers of each lookup can too.
 */
class IndexAdminBlogLookupRequest extends BlogPermissionRequest
{
    protected function permissions(): array
    {
        return [
            'blog.view-admin',
            'blog.category.manage',
            'blog.tag.manage',
            'blog.author.manage',
            'blog.collection.manage',
            'blog.placement.manage',
        ];
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            'placement_key' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(['name', 'newest', 'sort_order'])],
            ...$this->paginationRules(),
        ];
    }
}
