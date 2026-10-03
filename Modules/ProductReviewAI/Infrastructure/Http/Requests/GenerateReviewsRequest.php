<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Requests;

class GenerateReviewsRequest extends ProductReviewAIRequest
{
    public function rules(): array
    {
        $max = (int) config('product_review_ai.generation.max_count', 20);

        return [
            'count' => ['sometimes', 'integer', 'min:1', 'max:'.$max],
            'source_code' => ['sometimes', 'string', 'max:64'],
            'confirm' => ['sometimes', 'boolean'],
        ];
    }

    public function count(): int
    {
        return (int) $this->input('count', config('product_review_ai.generation.default_count', 3));
    }

    public function sourceCode(): string
    {
        return (string) $this->input('source_code', 'digikala');
    }

    public function confirmed(): bool
    {
        return $this->boolean('confirm');
    }
}
