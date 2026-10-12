<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Blog\Domain\Services\BlogSlugger;

/**
 * Create (POST) or update (PATCH `{tag}`) a tag (`blog.tag.manage`). Tags have
 * no description field — sending one is simply not accepted.
 */
class SaveBlogTagRequest extends ManageBlogTagsRequest
{
    public function rules(): array
    {
        $id = $this->route('tag');
        $creating = $id === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:'.BlogSlugger::PATTERN, Rule::unique('blog_tags', 'slug')->ignore($id)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
