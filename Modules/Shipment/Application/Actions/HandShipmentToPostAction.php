<?php

declare(strict_types=1);

namespace Modules\Shipment\Application\Actions;

use Modules\Shipment\Application\Services\ShipmentTransitionService;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;
use Modules\Shipment\Domain\Enums\ShipmentStatus;

/**
 * Final known postal state. Records the tracking number and postal handoff; the
 * parcel's downstream carrier lifecycle is intentionally not tracked.
 */
class HandShipmentToPostAction
{
    public function __construct(private readonly ShipmentTransitionService $transitions) {}

    public function handle(
        int $shipmentId,
        int $operatorId,
        string $trackingNumber,
        ?string $carrierName = null,
        ?string $note = null,
        ?int $postalReceiptMediaId = null,
    ): ShipmentDTO {
        return $this->transitions->transition(
            shipmentId: $shipmentId,
            to: ShipmentStatus::HandedToPost,
            changedByUserId: $operatorId,
            note: $note,
            attributes: [
                'tracking_number' => $trackingNumber,
                'carrier_name' => $carrierName,
                'postal_receipt_media_id' => $postalReceiptMediaId,
            ],
            historyMeta: ['tracking_number' => $trackingNumber],
        );
    }
}
