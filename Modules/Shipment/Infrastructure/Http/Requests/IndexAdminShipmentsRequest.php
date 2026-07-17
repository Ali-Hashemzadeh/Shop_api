<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexAdminShipmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('shipment.view-admin');
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string'],
            'method_code' => ['sometimes', 'string'],
            'method_type' => ['sometimes', 'string'],
            'order_id' => ['sometimes', 'integer'],
            'tracking_number' => ['sometimes', 'string'],
            'delivery_date' => ['sometimes', 'date'],
            'delivery_slot_id' => ['sometimes', 'integer'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer'],
        ];
    }
}
