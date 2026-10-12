<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Blog\Infrastructure\Http\Requests\Concerns\BlogPostRules;

/**
 * Create a draft article (`blog.post.create`). New posts always start as
 * `draft`; `status`, `public_code`, and `version` are server-owned.
 */
class StoreBlogPostRequest extends FormRequest
{
    use BlogPostRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('blog.post.create');
    }

    public function rules(): array
    {
        return $this->postRules(creating: true);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validatePostReferences($v));
    }
}
