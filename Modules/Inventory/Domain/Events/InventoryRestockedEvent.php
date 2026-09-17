<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a SKU's *available* stock crossed from
 * unavailable (<= 0) to available (> 0).
 *
 * Dispatched from inside the Inventory mutation transaction, but only on the
 * real transition — never on every stock change. Listeners implement
 * ShouldHandleEventsAfterCommit, so they run only once the surrounding
 * transaction commits: a rolled-back restock notifies nobody.
 *
 * Carries primitives only. Inventory knows SKUs, not products — the listener
 * resolves product name/code through Catalog's contract. Every restock carries
 * a UUID `eventId` so consumers can deduplicate a retried delivery, matching
 * OrderPaidEvent and the other integration events.
 */
class InventoryRestockedEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly string $sku,
        public readonly int $previousAvailableQuantity,
        public readonly int $newAvailableQuantity,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
