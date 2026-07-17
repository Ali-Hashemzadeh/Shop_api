<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Shipment\Domain\DTOs\ShipmentMethodDTO;

/** @mixin ShipmentMethodDTO */
class ShipmentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ShipmentMethodDTO $dto */
        $dto = $this->resource;

        $data = [
            'code' => $dto->code,
            'title' => $dto->title,
            'type' => $dto->type,
            'price' => $dto->price,
            'requires_address' => $dto->requiresAddress,
            'requires_delivery_slot' => $dto->requiresDeliverySlot,
            'supports_tracking' => $dto->supportsTracking,
            'estimated_min_days' => $dto->estimatedMinDays,
            'estimated_max_days' => $dto->estimatedMaxDays,
            'available' => $dto->available,
            'unavailable_reason' => $dto->unavailableReason,
        ];

        if ($dto->pickupLocation !== null) {
            $data['pickup_location'] = $dto->pickupLocation;
        }

        return $data;
    }
}
