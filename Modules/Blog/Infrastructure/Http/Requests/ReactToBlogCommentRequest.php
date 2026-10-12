<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Blog\Domain\Enums\BlogCommentReaction;

/** Set the caller's reaction on a visible comment (self-service). */
class ReactToBlogCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'reaction' => ['required', 'string', Rule::in(BlogCommentReaction::values())],
        ];
    }
}
