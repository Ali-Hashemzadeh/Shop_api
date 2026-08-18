<?php

declare(strict_types=1);

namespace Modules\Analytics\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexCustomerStatsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('analytics.view');
    }

    public function rules(): array
    {
        return [
            'sort' => ['nullable', 'string', 'in:orders_count,total_spent,total_discount_received,average_order_value,first_order_at,last_order_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc,ASC,DESC'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filters(): array
    {
        $filters = [];
        if ($this->filled('sort')) {
            $filters['sort'] = $this->string('sort')->trim()->toString();
        }
        if ($this->filled('direction')) {
            $filters['direction'] = $this->string('direction')->trim()->toString();
        }

        return $filters;
    }

    public function perPage(): int
    {
        return min(max($this->integer('per_page', 15), 1), 100);
    }
}
