<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\DTOs;

use Modules\Shipment\Domain\Models\DeliverySlot;

class DeliverySlotDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $date,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly int $capacity,
        public readonly int $adminReservedCapacity,
        public readonly int $remainingCapacity,
        public readonly string $status,
        public readonly bool $available,
        public readonly ?string $note = null,
    ) {}

    public static function fromModel(
        DeliverySlot $slot,
        int $remainingCapacity,
        bool $available,
    ): self {
        return new self(
            id: $slot->id,
            date: $slot->dateString(),
            startsAt: substr((string) $slot->starts_at, 0, 5),
            endsAt: substr((string) $slot->ends_at, 0, 5),
            capacity: $slot->capacity,
            adminReservedCapacity: $slot->admin_reserved_capacity,
            remainingCapacity: $remainingCapacity,
            status: $slot->status,
            available: $available,
            note: $slot->note,
        );
    }
}
