<?php

declare(strict_types=1);

namespace Modules\Shipment\Application\Actions;

use Modules\Shipment\Domain\Enums\DeliverySlotStatus;
use Modules\Shipment\Domain\Models\DeliverySlot;

class CloseDeliverySlotAction
{
    public function handle(int $slotId, ?string $note = null): DeliverySlot
    {
        $slot = DeliverySlot::findOrFail($slotId);
        $slot->update([
            'status' => DeliverySlotStatus::Closed->value,
            'note' => $note ?? $slot->note,
        ]);

        return $slot->fresh();
    }
}
