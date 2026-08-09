<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\DTOs;

use Carbon\Carbon;
use Modules\Shipment\Domain\Enums\ShipmentStatus;

/**
 * What a courier needs to complete a round, and deliberately nothing else.
 *
 * This is a separate, narrower carrier than [[ShipmentDTO]] rather than a filtered
 * view of it: the admin shipment surface will keep growing, and a driver's app
 * must not inherit whatever is added to it. Absent by construction — money of any
 * kind (order totals, coupons, payment state), the verification hash, and the
 * plaintext handoff code, which only the customer ever holds.
 */
class DeliveryAssignmentShipmentDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicCode,
        public readonly ShipmentStatus $status,
        /** Frozen at checkout — where the delivery was *ordered* to, not where the customer lives today. */
        public readonly ?array $addressSnapshot,
        public readonly ?array $deliverySlotSnapshot,
        public readonly ?string $customerName,
        public readonly ?string $customerPhone,
        public readonly ?string $receiverName,
        public readonly ?string $failureReason,
        public readonly ?string $note,
        public readonly ?Carbon $assignedAt,
        public readonly ?Carbon $outForDeliveryAt,
        public readonly ?Carbon $deliveredAt,
    ) {}
}
