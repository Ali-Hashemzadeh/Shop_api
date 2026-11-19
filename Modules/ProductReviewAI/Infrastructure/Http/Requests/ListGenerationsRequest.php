<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\ProductReviewAI\Domain\Enums\GenerationStatus;
use Modules\ProductReviewAI\Infrastructure\Http\Requests\Concerns\ParsesDateRange;

/**
 * GET /admin/ai-reviews/generations. Out-of-range pagination is rejected (422),
 * not clamped, so a dashboard bug is visible instead of silently paging wrong.
 */
class ListGenerationsRequest extends ProductReviewAIRequest
{
    use ParsesDateRange;

    public const SORTS = ['newest', 'oldest', 'longest_duration'];

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(GenerationStatus::values())],
            'product_uuid' => ['sometimes', 'string', 'max:64'],
            'source_id' => ['sometimes', 'integer', 'min:1'],
            'created_by' => ['sometimes', 'integer', 'min:1'],
            'model' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', 'string', Rule::in(self::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ...$this->dateRangeRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = [];

        foreach (['status', 'product_uuid', 'model', 'sort'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = (string) $this->validated($key);
            }
        }

        foreach (['source_id', 'created_by'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = (int) $this->validated($key);
            }
        }

        return $filters + $this->dateRange();
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 20);
    }
}
