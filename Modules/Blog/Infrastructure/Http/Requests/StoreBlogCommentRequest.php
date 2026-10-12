<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Blog\Infrastructure\Http\Requests\Concerns\ValidatesBlogMediaOwnership;

/**
 * Write a comment or reply (`blog.comment.create`). Comments are plain text —
 * never HTML; clients must render them escaped. Attachments must be Media the
 * caller uploaded. Post/parent eligibility is checked by the controller (404
 * for invisible targets, 422 for disabled comments / too-deep threads).
 */
class StoreBlogCommentRequest extends FormRequest
{
    use ValidatesBlogMediaOwnership;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('blog.comment.create');
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:'.(int) config('blog.comments.max_length', 5000)],
            ...$this->mediaIdsRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateMediaOwnership($v));
    }
}
