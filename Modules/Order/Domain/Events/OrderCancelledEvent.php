<?php

declare(strict_types=1);

namespace Modules\Order\Domain\Events;

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
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
    ) {}
}
