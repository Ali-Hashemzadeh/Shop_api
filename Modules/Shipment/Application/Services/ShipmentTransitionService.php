<?php

declare(strict_types=1);

namespace Modules\Shipment\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;
use Modules\Shipment\Domain\DTOs\ShipmentStatusHistoryDTO;
use Modules\Shipment\Domain\Enums\ReservationStatus;
use Modules\Shipment\Domain\Enums\ShipmentMethodType;
use Modules\Shipment\Domain\Enums\ShipmentStatus;
use Modules\Shipment\Domain\Events\ShipmentDeliveredEvent;
use Modules\Shipment\Domain\Events\ShipmentDeliveryFailedEvent;
use Modules\Shipment\Domain\Events\ShipmentHandedToPostEvent;
use Modules\Shipment\Domain\Events\ShipmentOutForDeliveryEvent;
use Modules\Shipment\Domain\Events\ShipmentPreparingStartedEvent;
use Modules\Shipment\Domain\Events\ShipmentReadyForPickupEvent;
use Modules\Shipment\Domain\Models\DeliverySlotReservation;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Shipment\Domain\Models\ShipmentStatusHistory;
use Modules\Shipment\Domain\Services\DeliveryVerificationCodeService;
use Modules\Shipment\Domain\Workflows\ShipmentWorkflowResolver;

/**
 * The single, method-aware primitive every status-changing Action funnels through:
 * lock → validate transition → mutate + timestamp → history → sync order → reservation.
 */
class ShipmentTransitionService
{
    /** Shipment status => the timestamp column stamped on entry. */
    private const TIMESTAMP_COLUMNS = [
        'preparing' => 'preparing_at',
        'ready_for_post' => 'ready_at',
        'ready_for_dispatch' => 'ready_at',
        'ready_for_pickup' => 'ready_for_pickup_at',
        'handed_to_post' => 'handed_to_post_at',
        'out_for_delivery' => 'out_for_delivery_at',
        'delivered' => 'delivered_at',
        'picked_up' => 'picked_up_at',
        'cancelled' => 'cancelled_at',
    ];

    public function __construct(
        private readonly ShipmentWorkflowResolver $workflows,
        private readonly OrderManagerInterface $orders,
        private readonly DeliveryVerificationCodeService $verificationCodes,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  extra shipment column updates
     * @param  array<string, mixed>  $historyMeta  metadata recorded on the history row
     */
    public function transition(
        int $shipmentId,
        ShipmentStatus $to,
        ?int $changedByUserId = null,
        ?string $reason = null,
        ?string $note = null,
        array $attributes = [],
        array $historyMeta = [],
    ): ShipmentDTO {
        return DB::transaction(function () use ($shipmentId, $to, $changedByUserId, $reason, $note, $attributes, $historyMeta): ShipmentDTO {
            /** @var Shipment $shipment */
            $shipment = Shipment::lockForUpdate()->findOrFail($shipmentId);

            $from = ShipmentStatus::from($shipment->status);
            $workflow = $this->workflows->forType($shipment->method_type);
            $workflow->assertCanTransition($from, $to);

            $this->assertDriverAssignedForDispatch($shipment, $to);

            // The handoff code is minted here, inside the same lock and the same
            // transaction as the status change, so a code can never exist for an
            // attempt that was rolled back — and an attempt can never go out
            // without one. The plaintext lives only in this local variable and
            // the SMS it is handed to; only the hash reaches the update below.
            $deliveryCode = null;

            if ($this->issuesVerificationCode($shipment, $to)) {
                ['code' => $deliveryCode, 'attributes' => $codeAttributes] = $this->verificationCodes->issue();
                $attributes = array_merge($attributes, $codeAttributes);
            }

            // A failed attempt retires its code immediately: whatever the customer
            // is still holding stops working, and the next dispatch mints a fresh one.
            if ($to === ShipmentStatus::DeliveryFailed) {
                $attributes = array_merge($attributes, $this->verificationCodes->invalidatedAttributes());
            }

            if ($to === ShipmentStatus::Delivered && $shipment->delivery_verification_code_hash !== null) {
                $attributes['delivery_verification_verified_at'] = now();
                $attributes['delivery_verification_code_hash'] = null;
            }

            $update = array_merge($attributes, ['status' => $to->value]);

            if (isset(self::TIMESTAMP_COLUMNS[$to->value])) {
                $update[self::TIMESTAMP_COLUMNS[$to->value]] = now();
            }

            $shipment->update($update);

            ShipmentStatusHistory::create([
                'shipment_id' => $shipment->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'changed_by_user_id' => $changedByUserId,
                'reason' => $reason,
                'note' => $note,
                'metadata' => empty($historyMeta) ? null : $historyMeta,
                'created_at' => now(),
            ]);

            // Update the Order summary status through the contract (no model import).
            $orderStatus = $to->toOrderStatus();
            if ($orderStatus !== null) {
                $this->orders->syncStatusFromShipment($shipment->order_id, $orderStatus);
            }

            $this->syncReservation($shipment, $to);

            $this->announce($shipment, $to, $deliveryCode);

            return $this->toDTO($shipment->fresh());
        });
    }

    /**
     * A local delivery may not leave the store without somebody carrying it.
     *
     * Enforced here rather than in the controller because both routes into
     * `out_for_delivery` — the first dispatch from `ready_for_dispatch` and the
     * retry from `delivery_failed` — funnel through this method, and because the
     * check must hold under the same row lock that performs the transition.
     */
    private function assertDriverAssignedForDispatch(Shipment $shipment, ShipmentStatus $to): void
    {
        if ($to !== ShipmentStatus::OutForDelivery) {
            return;
        }

        if ($shipment->method_type !== ShipmentMethodType::LocalDelivery->value) {
            return;
        }

        if ($shipment->assigned_delivery_user_id === null) {
            throw ValidationException::withMessages([
                'assigned_delivery_user_id' => ['Assign a delivery worker before dispatching this shipment.'],
            ]);
        }
    }

    /** Every local-delivery attempt gets its own code; postal and pickup get none. */
    private function issuesVerificationCode(Shipment $shipment, ShipmentStatus $to): bool
    {
        return $to === ShipmentStatus::OutForDelivery
            && $shipment->method_type === ShipmentMethodType::LocalDelivery->value;
    }

    /**
     * Publish the customer-facing milestones of an existing transition. No new
     * status is introduced — each event names a status the workflows already own.
     *
     * One event per business moment rather than one generic "sent" event for
     * both fulfillment shapes: handing a parcel to the post office and putting a
     * courier on the road are different things to say, and only one of them has a
     * tracking number to say it with.
     *
     * `picked_up` is intentionally silent — the customer is at the counter, and
     * `ready_for_pickup` already told them to come. Listeners run after commit,
     * so a rolled-back transition notifies nobody.
     *
     * `$deliveryCode` is the freshly minted local-delivery handoff code. It rides
     * along on the dispatch event so a dispatched local delivery still produces
     * exactly one customer SMS, and it is never stored anywhere.
     */
    private function announce(Shipment $shipment, ShipmentStatus $to, ?string $deliveryCode = null): void
    {
        // Resolved only for the statuses that actually notify, and only through the
        // Order contract — Shipment never touches the Order model. The customer's
        // message quotes this code rather than the internal order id.
        $orderPublicCode = match ($to) {
            ShipmentStatus::Preparing,
            ShipmentStatus::ReadyForPickup,
            ShipmentStatus::HandedToPost,
            ShipmentStatus::OutForDelivery,
            ShipmentStatus::Delivered => $this->orders->findOrder($shipment->order_id)?->publicCode,
            default => null,
        };

        $event = match ($to) {
            ShipmentStatus::Preparing => new ShipmentPreparingStartedEvent(
                orderId: $shipment->order_id,
                userId: $shipment->user_id,
                orderPublicCode: $orderPublicCode,
            ),
            ShipmentStatus::ReadyForPickup => new ShipmentReadyForPickupEvent(
                orderId: $shipment->order_id,
                userId: $shipment->user_id,
                orderPublicCode: $orderPublicCode,
            ),
            ShipmentStatus::HandedToPost => new ShipmentHandedToPostEvent(
                orderId: $shipment->order_id,
                userId: $shipment->user_id,
                orderPublicCode: $orderPublicCode,
                trackingCode: $shipment->tracking_number,
            ),
            ShipmentStatus::OutForDelivery => new ShipmentOutForDeliveryEvent(
                orderId: $shipment->order_id,
                userId: $shipment->user_id,
                orderPublicCode: $orderPublicCode,
                deliveryCode: $deliveryCode,
            ),
            ShipmentStatus::Delivered => new ShipmentDeliveredEvent(
                orderId: $shipment->order_id,
                userId: $shipment->user_id,
                orderPublicCode: $orderPublicCode,
                shipmentId: $shipment->id,
                method: $shipment->method_type,
                driverId: $shipment->assigned_delivery_user_id ? (int) $shipment->assigned_delivery_user_id : null,
                deliveryMinutes: $shipment->out_for_delivery_at ? max(1, (int) $shipment->out_for_delivery_at->diffInMinutes(now())) : null,
                deliveredAt: now()->toDateTimeString(),
            ),
            ShipmentStatus::DeliveryFailed => new ShipmentDeliveryFailedEvent(
                orderId: $shipment->order_id,
                userId: $shipment->user_id,
                orderPublicCode: $orderPublicCode,
                shipmentId: $shipment->id,
                method: $shipment->method_type,
                driverId: $shipment->assigned_delivery_user_id ? (int) $shipment->assigned_delivery_user_id : null,
                failedAt: now()->toDateTimeString(),
            ),
            default => null,
        };

        if ($event !== null) {
            Event::dispatch($event);
        }
    }

    private function syncReservation(Shipment $shipment, ShipmentStatus $to): void
    {
        if ($to === ShipmentStatus::Delivered) {
            DeliverySlotReservation::where('order_id', $shipment->order_id)
                ->whereIn('status', ReservationStatus::activeStatuses())
                ->update([
                    'status' => ReservationStatus::Completed->value,
                    'completed_at' => now(),
                ]);
        }

        if ($to === ShipmentStatus::Cancelled) {
            DeliverySlotReservation::where('order_id', $shipment->order_id)
                ->whereIn('status', ReservationStatus::activeStatuses())
                ->update([
                    'status' => ReservationStatus::Released->value,
                    'released_at' => now(),
                ]);
        }
    }

    public function toDTO(Shipment $shipment): ShipmentDTO
    {
        $history = $shipment->histories()->get()
            ->map(fn (ShipmentStatusHistory $h) => ShipmentStatusHistoryDTO::fromModel($h))
            ->all();

        return ShipmentDTO::fromModel($shipment, $history);
    }
}
