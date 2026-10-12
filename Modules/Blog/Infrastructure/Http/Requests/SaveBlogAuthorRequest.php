<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Modules\Blog\Domain\Services\BlogSlugger;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Media\Domain\Contracts\MediaManagerInterface;

/**
 * Create (POST) or update (PATCH `{author}`) an author profile
 * (`blog.author.manage`). The optional `user_id` is a loose Identity reference
 * checked through IdentityManagerInterface — never `exists:users,id`, which
 * would query Identity's table from Blog.
 */
class SaveBlogAuthorRequest extends ManageBlogAuthorsRequest
{
    public function rules(): array
    {
        $id = $this->route('author');
        $creating = $id === null;

        return [
            'display_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:180', 'regex:'.BlogSlugger::PATTERN, Rule::unique('blog_authors', 'slug')->ignore($id)],
            'user_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::unique('blog_authors', 'user_id')->ignore($id)],
            'bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'media_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->has('user_id')) {
                return;
            }

            if ($this->filled('user_id') && app(IdentityManagerInterface::class)->getUserSummaries([(int) $this->input('user_id')]) === []) {
                $v->errors()->add('user_id', 'The selected user does not exist.');
            }

            if ($this->filled('media_id') && app(MediaManagerInterface::class)->getMedia((int) $this->input('media_id')) === null) {
                $v->errors()->add('media_id', 'The selected media does not exist.');
            }
        });
    }
}
