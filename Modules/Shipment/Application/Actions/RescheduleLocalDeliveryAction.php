<?php

declare(strict_types=1);

namespace Modules\Shipment\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Shipment\Application\Services\DeliverySlotAvailabilityService;
use Modules\Shipment\Application\Services\ShipmentTransitionService;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;
use Modules\Shipment\Domain\Enums\ReservationStatus;
use Modules\Shipment\Domain\Enums\ShipmentMethodType;
use Modules\Shipment\Domain\Enums\ShipmentStatus;
use Modules\Shipment\Domain\Models\DeliverySlot;
use Modules\Shipment\Domain\Models\DeliverySlotReservation;
use Modules\Shipment\Domain\Models\Shipment;

/**
 * Move a failed local delivery to a new slot: lock + validate the new slot, release
 * the old reservation, create a fresh confirmed reservation, update the snapshot,
 * and transition back to ready_for_dispatch — all atomically, without overbooking.
 */
class RescheduleLocalDeliveryAction
{
    public function __construct(
        private readonly DeliverySlotAvailabilityService $availability,
        private readonly ShipmentTransitionService $transitions,
    ) {}

    public function handle(int $shipmentId, int $operatorId, int $newSlotId, ?string $note = null): ShipmentDTO
    {
        return DB::transaction(function () use ($shipmentId, $operatorId, $newSlotId, $note): ShipmentDTO {
            /** @var Shipment $shipment */
            $shipment = Shipment::lockForUpdate()->findOrFail($shipmentId);

            if ($shipment->method_type !== ShipmentMethodType::LocalDelivery->value) {
                throw ValidationException::withMessages([
                    'shipment' => ['Only local delivery shipments can be rescheduled.'],
                ]);
            }

            /** @var DeliverySlot|null $slot */
            $slot = DeliverySlot::lockForUpdate()->find($newSlotId);

            if ($slot === null || ! $this->availability->isSelectable($slot) || $this->availability->remainingCapacity($slot) <= 0) {
                throw ValidationException::withMessages([
                    'delivery_slot_id' => ['The selected delivery time is no longer available.'],
                ]);
            }

            $oldSnapshot = $shipment->delivery_slot_snapshot;

            // Release the old active reservation for this order.
            DeliverySlotReservation::where('order_id', $shipment->order_id)
                ->whereIn('status', ReservationStatus::activeStatuses())
                ->update(['status' => ReservationStatus::Released->value, 'released_at' => now()]);

            // Create the new confirmed reservation on the new slot.
            DeliverySlotReservation::create([
                'delivery_slot_id' => $slot->id,
                'order_id' => $shipment->order_id,
                'user_id' => $shipment->user_id,
                'status' => ReservationStatus::Confirmed->value,
                'confirmed_at' => now(),
            ]);

            $newSnapshot = [
                'slot_id' => $slot->id,
                'date' => $slot->dateString(),
                'starts_at' => substr((string) $slot->starts_at, 0, 8),
                'ends_at' => substr((string) $slot->ends_at, 0, 8),
            ];

            return $this->transitions->transition(
                shipmentId: $shipment->id,
                to: ShipmentStatus::ReadyForDispatch,
                changedByUserId: $operatorId,
                reason: 'rescheduled',
                note: $note,
                attributes: ['delivery_slot_snapshot' => $newSnapshot, 'failure_reason' => null],
                historyMeta: ['old_slot' => $oldSnapshot, 'new_slot' => $newSnapshot],
            );
        });
    }
}
