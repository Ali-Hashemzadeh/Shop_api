<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Modules\Blog\Infrastructure\Http\Requests\Concerns\BlogPostRules;
use Modules\Blog\Infrastructure\Http\Requests\Concerns\ResolvesAdminBlogPost;

/**
 * A manual save. Unknown post → 404; not allowed by BlogPostPolicy::update →
 * 403 (editors with `blog.post.update-any`, or authors with
 * `blog.post.update-own` on their own draft); then validation. `version` is
 * the version the editor loaded — a stale one is a 409 from the Action.
 */
class UpdateBlogPostRequest extends FormRequest
{
    use BlogPostRules;
    use ResolvesAdminBlogPost;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->blogPost());
    }

    public function rules(): array
    {
        return [
            ...$this->postRules(creating: false, ignorePostId: (int) $this->blogPost()->id),
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validatePostReferences(
            $v,
            DB::table('blog_post_category')->where('blog_post_id', $this->blogPost()->id)->pluck('blog_category_id')->map(fn ($id) => (int) $id)->all(),
        ));
    }
}
