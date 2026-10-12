<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Blog\Infrastructure\Http\Requests\Concerns\ResolvesAdminBlogPost;

/** Schedule an approved post (`blog.post.publish`). `published_at` must be in the future. */
class ScheduleBlogPostRequest extends FormRequest
{
    use ResolvesAdminBlogPost;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('publish', $this->blogPost());
    }

    public function rules(): array
    {
        return [
            'published_at' => ['required', 'date', 'after:now'],
            'unpublish_at' => ['sometimes', 'nullable', 'date', 'after:published_at'],
        ];
    }
}
