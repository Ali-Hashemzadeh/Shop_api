<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Blog\Infrastructure\Http\Requests\Concerns\ResolvesAdminBlogPost;

/** Send a post back to draft (`blog.post.approve`), with an optional reason for the audit trail. */
class ReturnBlogPostToDraftRequest extends FormRequest
{
    use ResolvesAdminBlogPost;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('returnToDraft', $this->blogPost());
    }

    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
