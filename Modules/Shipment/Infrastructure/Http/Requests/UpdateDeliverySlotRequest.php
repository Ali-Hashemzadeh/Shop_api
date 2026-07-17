<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeliverySlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('shipment.slot.manage');
    }

    public function rules(): array
    {
        return [
            'capacity' => ['sometimes', 'integer', 'min:0'],
            'admin_reserved_capacity' => ['sometimes', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
