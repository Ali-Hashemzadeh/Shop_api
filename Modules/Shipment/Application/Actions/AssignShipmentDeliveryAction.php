<?php

declare(strict_types=1);

namespace Modules\Shipment\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Shipment\Application\Services\ShipmentTransitionService;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;
use Modules\Shipment\Domain\Enums\ShipmentMethodType;
use Modules\Shipment\Domain\Enums\ShipmentStatus;
use Modules\Shipment\Domain\Events\ShipmentAssignedToDeliveryEvent;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Shipment\Domain\Models\ShipmentDeliveryAssignment;

/**
 * Put a delivery worker in charge of a local-delivery shipment.
 *
 * Assignment belongs to the *shipment*, never to the order: an order is a
 * commercial record, while the thing a courier carries — with an address, a slot
 * and a status — is the shipment. Only local deliveries can be assigned at all;
 * a postal parcel is handed to a carrier and a pickup is collected at the counter.
 *
 * Reassignment is a first-class operation (drivers get sick, shifts end). It
 * closes the open history row and opens a new one under one transaction, so the
 * audit never shows two people holding the same shipment at the same moment.
 * Assigning the driver who already holds it is a no-op that notifies nobody.
 */
class AssignShipmentDeliveryAction
{
    public function __construct(
        private readonly ShipmentTransitionService $transitions,
        private readonly IdentityManagerInterface $identity,
        private readonly OrderManagerInterface $orders,
    ) {}

    public function handle(int $shipmentId, int $deliveryUserId, int $assignedByUserId): ShipmentDTO
    {
        $this->assertValidDeliveryUser($deliveryUserId);

        [$shipment, $changed] = DB::transaction(function () use ($shipmentId, $deliveryUserId, $assignedByUserId): array {
            /** @var Shipment $shipment */
            $shipment = Shipment::lockForUpdate()->findOrFail($shipmentId);

            $this->assertAssignable($shipment);

            // Idempotent: the same driver is already responsible, so there is
            // nothing to record and nobody new to tell.
            if ((int) $shipment->assigned_delivery_user_id === $deliveryUserId) {
                return [$shipment, false];
            }

            ShipmentDeliveryAssignment::where('shipment_id', $shipment->id)
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now(), 'updated_at' => now()]);

            ShipmentDeliveryAssignment::create([
                'shipment_id' => $shipment->id,
                'delivery_user_id' => $deliveryUserId,
                'assigned_by_user_id' => $assignedByUserId,
                'assigned_at' => now(),
            ]);

            $shipment->update([
                'assigned_delivery_user_id' => $deliveryUserId,
                'delivery_assigned_at' => now(),
            ]);

            return [$shipment->fresh(), true];
        });

        if ($changed) {
            // Dispatched outside the transaction body only for readability — the
            // listener implements ShouldHandleEventsAfterCommit either way, so a
            // rolled-back assignment can never page a driver.
            Event::dispatch($this->assignedEvent($shipment, $deliveryUserId));
        }

        return $this->transitions->toDTO($shipment);
    }

    /**
     * A courier must be a real delivery user with a reachable phone. Both facts
     * are read through Identity's contract — Shipment never touches the User model.
     */
    private function assertValidDeliveryUser(int $deliveryUserId): void
    {
        if (! $this->identity->isDeliveryUser($deliveryUserId)) {
            throw ValidationException::withMessages([
                'delivery_user_id' => ['The selected user is not a delivery worker.'],
            ]);
        }

        $phone = $this->identity->getUserSummary($deliveryUserId)->phone;

        if ($phone === null || $phone === '') {
            throw ValidationException::withMessages([
                'delivery_user_id' => ['The selected delivery worker has no phone number.'],
            ]);
        }
    }

    private function assertAssignable(Shipment $shipment): void
    {
        if ($shipment->method_type !== ShipmentMethodType::LocalDelivery->value) {
            throw ValidationException::withMessages([
                'delivery_user_id' => ['Only local delivery shipments can be assigned to a delivery worker.'],
            ]);
        }

        $status = ShipmentStatus::from($shipment->status);

        if ($status === ShipmentStatus::Delivered || $status === ShipmentStatus::Cancelled) {
            throw ValidationException::withMessages([
                'delivery_user_id' => ['This shipment is already finished and cannot be assigned.'],
            ]);
        }
    }

    private function assignedEvent(Shipment $shipment, int $deliveryUserId): ShipmentAssignedToDeliveryEvent
    {
        $slot = $shipment->delivery_slot_snapshot ?? [];

        return new ShipmentAssignedToDeliveryEvent(
            shipmentId: $shipment->id,
            shipmentPublicCode: $shipment->public_code,
            orderId: $shipment->order_id,
            deliveryUserId: $deliveryUserId,
            orderPublicCode: $this->orders->findOrder($shipment->order_id)?->publicCode,
            deliveryDate: $slot['date'] ?? null,
            deliveryStartsAt: $slot['starts_at'] ?? null,
            deliveryEndsAt: $slot['ends_at'] ?? null,
        );
    }
}
