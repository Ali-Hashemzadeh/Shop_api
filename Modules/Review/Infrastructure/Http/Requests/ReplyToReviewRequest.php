<?php

declare(strict_types=1);

namespace Modules\Review\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The single seller reply. One reply per review, overwritable, no thread.
 */
class ReplyToReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('review.moderate');
    }

    public function rules(): array
    {
        return [
            'reply' => ['required', 'string'],
        ];
    }
}
