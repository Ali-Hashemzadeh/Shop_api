<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Shipment\Domain\Enums\ShipmentStatus;

class IndexDeliveryShipmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('shipment.delivery.view-assigned');
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            // Only the statuses a local delivery can actually be in — a driver
            // filtering by `handed_to_post` is asking a meaningless question.
            'status' => ['sometimes', 'string', Rule::in([
                ShipmentStatus::Pending->value,
                ShipmentStatus::Preparing->value,
                ShipmentStatus::ReadyForDispatch->value,
                ShipmentStatus::OutForDelivery->value,
                ShipmentStatus::Delivered->value,
                ShipmentStatus::DeliveryFailed->value,
                ShipmentStatus::Cancelled->value,
            ])],
        ];
    }
}
