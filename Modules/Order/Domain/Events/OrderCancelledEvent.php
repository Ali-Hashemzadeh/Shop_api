<?php

declare(strict_types=1);

namespace Modules\Order\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a customer- or admin-initiated cancellation
 * completed (stock released, slot released, status set to cancelled).
 *
 * Deliberately not dispatched by the internal pending-order replacement inside
 * CreateOrderAction, which cancels a superseded draft the customer never asked
 * about. Carries primitives only.
 */
class OrderCancelledEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        /** Customer-facing code (`bdo-XXXXXX`), alongside — not replacing — the id. */
        public readonly ?string $orderPublicCode = null,
        public readonly int $refundAmount = 0,
        public readonly bool $wasPaid = false,
        public readonly ?string $cancelledAt = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
