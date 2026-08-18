<?php

declare(strict_types=1);

namespace Modules\Order\Domain\Events;

use Illuminate\Support\Str;
use Modules\Order\Domain\DTOs\OrderPaidItemDTO;

/**
 * Published integration event: an order actually transitioned to paid.
 *
 * Dispatched from the shared `markAsPaid` path *after* the status change,
 * inventory commit, and shipment activation — and only on the real transition,
 * never on an idempotent repeat call. Listeners implementing
 * ShouldHandleEventsAfterCommit therefore run only if the surrounding
 * transaction commits.
 *
 * Carries primitives and DTOs only: no Eloquent model ever crosses a module wall.
 */
class OrderPaidEvent
{
    public readonly string $eventId;

    /**
     * @param  list<OrderPaidItemDTO>  $items
     */
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        public readonly int $totalAmount,
        /**
         * The order's customer-facing code (`bdo-XXXXXX`), carried alongside the
         * numeric id rather than replacing it: listeners show the code to the
         * customer but still need the id for in-app deep links.
         */
        public readonly ?string $orderPublicCode = null,
        public readonly int $discountAmount = 0,
        public readonly int $couponAmount = 0,
        public readonly ?int $couponId = null,
        public readonly array $items = [],
        public readonly ?string $paidAt = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
