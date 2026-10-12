<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Blog\Infrastructure\Http\Requests\Concerns\ResolvesAdminBlogPost;

/**
 * Restore a revision as a new current version. Same authorization as a manual
 * save (BlogPostPolicy::update) and the same optimistic-lock `version`.
 */
class RestoreBlogRevisionRequest extends FormRequest
{
    use ResolvesAdminBlogPost;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->blogPost());
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
