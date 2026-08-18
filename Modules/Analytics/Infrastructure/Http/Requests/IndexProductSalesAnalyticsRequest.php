<?php

declare(strict_types=1);

namespace Modules\Analytics\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexProductSalesAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('analytics.view');
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'category_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filters(): array
    {
        $filters = [];
        if ($this->filled('from')) {
            $filters['from'] = $this->string('from')->trim()->toString();
        }
        if ($this->filled('to')) {
            $filters['to'] = $this->string('to')->trim()->toString();
        }
        if ($this->filled('category_id')) {
            $filters['category_id'] = $this->integer('category_id');
        }

        return $filters;
    }
}
