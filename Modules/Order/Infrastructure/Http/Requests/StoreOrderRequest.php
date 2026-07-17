<?php

declare(strict_types=1);

namespace Modules\Order\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('order.create');
    }

    public function rules(): array
    {
        return [
            'shipment_method_code' => ['required', 'string'],
            'address_id' => ['nullable', 'integer'],
            'delivery_slot_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
