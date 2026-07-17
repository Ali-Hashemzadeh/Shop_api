<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\DTOs;

use Carbon\Carbon;
use Modules\Shipment\Domain\Enums\ShipmentStatus;
use Modules\Shipment\Domain\Models\Shipment;

class ShipmentDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicCode,
        public readonly int $orderId,
        public readonly int $userId,
        public readonly string $methodCode,
        public readonly string $methodTitle,
        public readonly string $methodType,
        public readonly int $shippingCost,
        public readonly ShipmentStatus $status,
        public readonly ?array $addressSnapshot,
        public readonly ?array $deliverySlotSnapshot,
        public readonly ?array $pickupLocationSnapshot,
        public readonly ?string $carrierName,
        public readonly ?string $trackingNumber,
        public readonly ?string $receiverName,
        public readonly ?string $failureReason,
        public readonly ?string $note,
        public readonly ?Carbon $handedToPostAt,
        public readonly ?Carbon $outForDeliveryAt,
        public readonly ?Carbon $deliveredAt,
        public readonly ?Carbon $readyForPickupAt,
        public readonly ?Carbon $pickedUpAt,
        public readonly Carbon $createdAt,
        /** @var ShipmentStatusHistoryDTO[] */
        public readonly array $history = [],
    ) {}

    /** @param ShipmentStatusHistoryDTO[] $history */
    public static function fromModel(Shipment $shipment, array $history = []): self
    {
        return new self(
            id: $shipment->id,
            publicCode: $shipment->public_code,
            orderId: $shipment->order_id,
            userId: $shipment->user_id,
            methodCode: $shipment->method_code,
            methodTitle: $shipment->method_title,
            methodType: $shipment->method_type,
            shippingCost: $shipment->shipping_cost,
            status: ShipmentStatus::from($shipment->status),
            addressSnapshot: $shipment->address_snapshot,
            deliverySlotSnapshot: $shipment->delivery_slot_snapshot,
            pickupLocationSnapshot: $shipment->pickup_location_snapshot,
            carrierName: $shipment->carrier_name,
            trackingNumber: $shipment->tracking_number,
            receiverName: $shipment->receiver_name,
            failureReason: $shipment->failure_reason,
            note: $shipment->note,
            handedToPostAt: $shipment->handed_to_post_at,
            outForDeliveryAt: $shipment->out_for_delivery_at,
            deliveredAt: $shipment->delivered_at,
            readyForPickupAt: $shipment->ready_for_pickup_at,
            pickedUpAt: $shipment->picked_up_at,
            createdAt: Carbon::parse($shipment->created_at),
            history: $history,
        );
    }
}
