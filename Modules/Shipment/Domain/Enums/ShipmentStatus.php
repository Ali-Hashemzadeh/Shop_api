<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Enums;

enum ShipmentStatus: string
{
    case Pending = 'pending';
    case Preparing = 'preparing';

    // Postal (standard + express)
    case ReadyForPost = 'ready_for_post';
    case HandedToPost = 'handed_to_post';

    // Local delivery
    case ReadyForDispatch = 'ready_for_dispatch';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case DeliveryFailed = 'delivery_failed';

    // Pickup
    case ReadyForPickup = 'ready_for_pickup';
    case PickedUp = 'picked_up';

    case Cancelled = 'cancelled';

    /**
     * Customer-facing label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for processing',
            self::Preparing => 'Preparing your order',
            self::ReadyForPost => 'Ready to hand to postal service',
            self::HandedToPost => 'Handed to postal service',
            self::ReadyForDispatch => 'Ready for delivery',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::DeliveryFailed => 'Delivery was unsuccessful',
            self::ReadyForPickup => 'Ready for pickup',
            self::PickedUp => 'Picked up',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Map the detailed shipment status to the Order summary status.
     * Returns null when the shipment status should not move the order.
     */
    public function toOrderStatus(): ?string
    {
        return match ($this) {
            self::Pending => 'paid',
            self::Preparing, self::ReadyForPost, self::ReadyForDispatch, self::ReadyForPickup => 'processing',
            self::HandedToPost, self::OutForDelivery => 'shipped',
            self::Delivered, self::PickedUp => 'completed',
            default => null,
        };
    }
}
