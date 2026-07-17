<?php

declare(strict_types=1);

namespace Modules\Shipment\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;
use Modules\Shipment\Domain\DTOs\ShipmentStatusHistoryDTO;
use Modules\Shipment\Domain\Enums\ReservationStatus;
use Modules\Shipment\Domain\Enums\ShipmentStatus;
use Modules\Shipment\Domain\Models\DeliverySlotReservation;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Shipment\Domain\Models\ShipmentStatusHistory;
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

            return $this->toDTO($shipment->fresh());
        });
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
