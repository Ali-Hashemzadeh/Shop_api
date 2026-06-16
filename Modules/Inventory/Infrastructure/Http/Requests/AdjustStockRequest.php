<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.stock.manage');
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:255'],
            'quantity_change' => ['required', 'integer', 'not_in:0'],
            'type' => ['required', 'string', 'in:restock,adjustment,return'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
