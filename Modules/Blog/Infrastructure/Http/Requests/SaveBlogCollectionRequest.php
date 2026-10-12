<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Blog\Domain\Services\BlogSlugger;

/** Create (POST) or update (PATCH `{collection}`) an editorial collection (`blog.collection.manage`). */
class SaveBlogCollectionRequest extends ManageBlogCollectionsRequest
{
    public function rules(): array
    {
        $id = $this->route('collection');
        $creating = $id === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:180', 'regex:'.BlogSlugger::PATTERN, Rule::unique('blog_collections', 'slug')->ignore($id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'post_ids' => ['sometimes', 'array', 'max:200'],
            'post_ids.*' => ['integer', 'distinct', Rule::exists('blog_posts', 'id')->whereNull('deleted_at')],
        ];
    }
}
