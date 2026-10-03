<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Requests;

class SaveMappingRequest extends ProductReviewAIRequest
{
    public function rules(): array
    {
        return [
            'source_code' => ['required', 'string', 'max:64'],
            'external_id' => ['required', 'string', 'max:255'],
            'external_url' => ['nullable', 'url', 'max:2048'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
