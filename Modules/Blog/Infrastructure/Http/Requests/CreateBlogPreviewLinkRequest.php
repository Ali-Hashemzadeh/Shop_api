<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Blog\Infrastructure\Http\Requests\Concerns\ResolvesAdminBlogPost;

/**
 * Mint a signed preview URL (`blog.view-admin`). Optionally pinned to one of
 * this post's revisions.
 */
class CreateBlogPreviewLinkRequest extends FormRequest
{
    use ResolvesAdminBlogPost;

    public function authorize(): bool
    {
        $post = $this->blogPost();

        return (bool) $this->user()?->can('blog.view-admin') || (bool) $this->user()?->can('collaborate', $post);
    }

    public function rules(): array
    {
        return [
            'revision_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('blog_post_revisions', 'id')->where('blog_post_id', $this->blogPost()->id),
            ],
        ];
    }
}
