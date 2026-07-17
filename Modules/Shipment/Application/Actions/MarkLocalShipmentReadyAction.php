<?php

declare(strict_types=1);

namespace Modules\Shipment\Application\Actions;

use Modules\Shipment\Application\Services\ShipmentTransitionService;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;
use Modules\Shipment\Domain\Enums\ShipmentStatus;

class MarkLocalShipmentReadyAction
{
    public function __construct(private readonly ShipmentTransitionService $transitions) {}

    public function handle(int $shipmentId, int $operatorId, ?string $note = null): ShipmentDTO
    {
        return $this->transitions->transition(
            shipmentId: $shipmentId,
            to: ShipmentStatus::ReadyForDispatch,
            changedByUserId: $operatorId,
            note: $note,
        );
    }
}
