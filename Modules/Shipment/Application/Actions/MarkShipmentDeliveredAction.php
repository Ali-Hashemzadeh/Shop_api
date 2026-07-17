<?php

declare(strict_types=1);

namespace Modules\Shipment\Application\Actions;

use Modules\Shipment\Application\Services\ShipmentTransitionService;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;
use Modules\Shipment\Domain\Enums\ShipmentStatus;

class MarkShipmentDeliveredAction
{
    public function __construct(private readonly ShipmentTransitionService $transitions) {}

    public function handle(
        int $shipmentId,
        int $operatorId,
        ?string $receiverName = null,
        ?string $note = null,
        ?int $proofMediaId = null,
    ): ShipmentDTO {
        return $this->transitions->transition(
            shipmentId: $shipmentId,
            to: ShipmentStatus::Delivered,
            changedByUserId: $operatorId,
            note: $note,
            attributes: [
                'receiver_name' => $receiverName,
                'proof_media_id' => $proofMediaId,
            ],
            historyMeta: $receiverName !== null ? ['receiver_name' => $receiverName] : [],
        );
    }
}
