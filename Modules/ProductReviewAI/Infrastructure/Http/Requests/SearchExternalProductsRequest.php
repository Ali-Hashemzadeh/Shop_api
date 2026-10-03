<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Requests;

class SearchExternalProductsRequest extends ProductReviewAIRequest
{
    public function rules(): array
    {
        return [
            'source_code' => ['required', 'string', 'max:64'],
            'q' => ['required', 'string', 'min:2', 'max:255'],
        ];
    }
}
