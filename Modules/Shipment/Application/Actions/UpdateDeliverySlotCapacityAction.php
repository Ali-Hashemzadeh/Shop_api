<?php

declare(strict_types=1);

namespace Modules\Shipment\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Shipment\Application\Services\DeliverySlotAvailabilityService;
use Modules\Shipment\Domain\Models\DeliverySlot;

/**
 * Adjust a slot's capacity and/or the portion reserved for telephone/internal
 * orders. Effective bookable capacity may never drop below active reservations.
 */
class UpdateDeliverySlotCapacityAction
{
    public function __construct(private readonly DeliverySlotAvailabilityService $availability) {}

    public function handle(
        int $slotId,
        ?int $capacity = null,
        ?int $adminReservedCapacity = null,
        ?string $note = null,
    ): DeliverySlot {
        return DB::transaction(function () use ($slotId, $capacity, $adminReservedCapacity, $note): DeliverySlot {
            /** @var DeliverySlot $slot */
            $slot = DeliverySlot::lockForUpdate()->findOrFail($slotId);

            $newCapacity = $capacity ?? $slot->capacity;
            $newAdminReserved = $adminReservedCapacity ?? $slot->admin_reserved_capacity;
            $active = $this->availability->activeReservationCount($slot->id);

            if (($newCapacity - $newAdminReserved) < $active) {
                throw ValidationException::withMessages([
                    'capacity' => ['Effective capacity cannot be reduced below existing active reservations.'],
                ]);
            }

            $slot->update([
                'capacity' => $newCapacity,
                'admin_reserved_capacity' => $newAdminReserved,
                'note' => $note ?? $slot->note,
            ]);

            return $slot->fresh();
        });
    }
}
