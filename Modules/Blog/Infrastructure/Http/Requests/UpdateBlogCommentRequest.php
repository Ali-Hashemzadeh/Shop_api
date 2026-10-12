<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use App\Support\PublicCodeGenerator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Blog\Domain\Models\BlogComment;
use Modules\Blog\Infrastructure\Http\Requests\Concerns\ValidatesBlogMediaOwnership;

/**
 * The author edits their own comment: unknown/deleted code → 404, not the
 * owner → 403 (BlogCommentPolicy::update), then validation.
 */
class UpdateBlogCommentRequest extends FormRequest
{
    use ValidatesBlogMediaOwnership;

    private ?BlogComment $resolvedComment = null;

    public function comment(): BlogComment
    {
        return $this->resolvedComment ??= BlogComment::query()
            ->where('public_code', PublicCodeGenerator::normalize((string) $this->route('publicCode')))
            ->firstOrFail();
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->comment());
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
