<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Requests;

use Modules\ProductReviewAI\Infrastructure\Http\Requests\Concerns\ParsesDateRange;

class GenerationStatsRequest extends ProductReviewAIRequest
{
    use ParsesDateRange;

    public function rules(): array
    {
        return [
            'product_uuid' => ['sometimes', 'string', 'max:64'],
            ...$this->dateRangeRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = $this->dateRange();

        if ($this->filled('product_uuid')) {
            $filters['product_uuid'] = (string) $this->validated('product_uuid');
        }

        return $filters;
    }
}
