<?php

declare(strict_types=1);

namespace Modules\Analytics\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexDeliveryStatsRequest extends FormRequest
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
            'method' => ['nullable', 'string', 'max:50'],
            'driver_id' => ['nullable', 'integer', 'min:1'],
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
        if ($this->filled('method')) {
            $filters['method'] = $this->string('method')->trim()->toString();
        }
        if ($this->filled('driver_id')) {
            $filters['driver_id'] = $this->integer('driver_id');
        }

        return $filters;
    }
}
