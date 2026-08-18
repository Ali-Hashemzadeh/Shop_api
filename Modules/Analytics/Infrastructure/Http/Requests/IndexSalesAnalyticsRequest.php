<?php

declare(strict_types=1);

namespace Modules\Analytics\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexSalesAnalyticsRequest extends FormRequest
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

        return $filters;
    }
}
