<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Shipment\Domain\DTOs\DeliverySlotDTO;

/** @mixin DeliverySlotDTO */
class DeliverySlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DeliverySlotDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'date' => $dto->date,
            'starts_at' => $dto->startsAt,
            'ends_at' => $dto->endsAt,
            'capacity' => $dto->capacity,
            'admin_reserved_capacity' => $dto->adminReservedCapacity,
            'remaining_capacity' => $dto->remainingCapacity,
            'status' => $dto->status,
            'available' => $dto->available,
            'note' => $dto->note,
        ];
    }
}
