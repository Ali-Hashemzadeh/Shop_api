<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Requests;

class UpdateDeliveryWorkingPeriodRequest extends StoreDeliveryWorkingPeriodRequest
{
    public function rules(): array
    {
        return [
            'weekday' => ['sometimes', 'integer', 'between:0,6'],
            'starts_at' => ['sometimes', 'date_format:H:i'],
            'ends_at' => ['sometimes', 'date_format:H:i'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
