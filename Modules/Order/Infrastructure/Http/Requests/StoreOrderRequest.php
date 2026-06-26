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
            'address_id' => ['required', 'integer'],
            'shipment_method_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
