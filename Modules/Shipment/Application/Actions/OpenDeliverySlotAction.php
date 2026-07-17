<?php

declare(strict_types=1);

namespace Modules\Shipment\Application\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Shipment\Domain\Enums\DeliverySlotStatus;
use Modules\Shipment\Domain\Models\DeliverySlot;

class OpenDeliverySlotAction
{
    public function handle(int $slotId, ?string $note = null): DeliverySlot
    {
        $slot = DeliverySlot::findOrFail($slotId);

        if ($slot->status === DeliverySlotStatus::Cancelled->value) {
            throw ValidationException::withMessages([
                'status' => ['A cancelled slot cannot be reopened.'],
            ]);
        }

        $slot->update([
            'status' => DeliverySlotStatus::Open->value,
            'note' => $note ?? $slot->note,
        ]);

        return $slot->fresh();
    }
}
