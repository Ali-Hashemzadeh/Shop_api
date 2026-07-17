<?php

declare(strict_types=1);

namespace Modules\Shipment\Application\Actions;

use Modules\Shipment\Application\Services\ShipmentTransitionService;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;
use Modules\Shipment\Domain\Enums\ShipmentStatus;

class MarkDeliveryFailedAction
{
    public function __construct(private readonly ShipmentTransitionService $transitions) {}

    public function handle(int $shipmentId, int $operatorId, string $failureReason, ?string $note = null): ShipmentDTO
    {
        return $this->transitions->transition(
            shipmentId: $shipmentId,
            to: ShipmentStatus::DeliveryFailed,
            changedByUserId: $operatorId,
            reason: $failureReason,
            note: $note,
            attributes: ['failure_reason' => $failureReason],
            historyMeta: ['failure_reason' => $failureReason],
        );
    }
}
