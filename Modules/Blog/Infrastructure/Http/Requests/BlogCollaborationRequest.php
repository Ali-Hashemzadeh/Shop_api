<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Blog\Infrastructure\Http\Requests\Concerns\ResolvesAdminBlogPost;

/**
 * Editorial collaboration on one post (notes list, autosave recovery):
 * BlogPostPolicy::collaborate.
 */
class BlogCollaborationRequest extends FormRequest
{
    use ResolvesAdminBlogPost;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('collaborate', $this->blogPost());
    }

    public function rules(): array
    {
        return [];
    }
}
