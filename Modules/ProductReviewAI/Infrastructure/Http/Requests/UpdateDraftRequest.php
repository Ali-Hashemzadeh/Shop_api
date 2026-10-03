<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Requests;

class UpdateDraftRequest extends ProductReviewAIRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'rating' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'string', 'min:1'],
        ];
    }

    /**
     * Only the fields actually present become changes — an absent field is left
     * untouched.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return $this->only(array_keys($this->rules()));
    }
}
