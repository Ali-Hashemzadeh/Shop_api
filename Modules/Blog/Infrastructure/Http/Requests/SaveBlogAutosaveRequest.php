<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Blog\Infrastructure\Http\Requests\Concerns\ResolvesAdminBlogPost;

/** Store the caller's autosave for a post they may edit. */
class SaveBlogAutosaveRequest extends FormRequest
{
    use ResolvesAdminBlogPost;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->blogPost());
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'content' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('blog.content.max_length', 1_000_000)],
        ];
    }
}
