<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Shipment\Domain\DTOs\ShipmentStatusHistoryDTO;

/** @mixin ShipmentStatusHistoryDTO */
class ShipmentStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ShipmentStatusHistoryDTO $dto */
        $dto = $this->resource;

        return [
            'from_status' => $dto->fromStatus,
            'to_status' => $dto->toStatus,
            'reason' => $dto->reason,
            'note' => $dto->note,
            'metadata' => $dto->metadata,
            'created_at' => $dto->createdAt->toISOString(),
        ];
    }
}
