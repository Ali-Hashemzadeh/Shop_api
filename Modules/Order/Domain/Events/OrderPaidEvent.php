<?php

declare(strict_types=1);

namespace Modules\Order\Domain\Events;

/**
 * Published integration event: an order actually transitioned to paid.
 *
 * Dispatched from the shared `markAsPaid` path *after* the status change,
 * inventory commit, and shipment activation — and only on the real transition,
 * never on an idempotent repeat call. Listeners implementing
 * ShouldHandleEventsAfterCommit therefore run only if the surrounding
 * transaction commits.
 *
 * Carries primitives only: no Order, User, or Payment model ever crosses a
 * module wall.
 */
class OrderPaidEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        public readonly int $totalAmount,
    ) {}
}
