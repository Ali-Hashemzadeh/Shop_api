<?php

declare(strict_types=1);

namespace Modules\Payment\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: server-side gateway verification rejected the
 * payment. Dispatched only from the verification-failure branch — not when the
 * customer simply abandons the gateway page. Carries primitives only.
 */
class PaymentFailedEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        /** Customer-facing code (`bdo-XXXXXX`), alongside — not replacing — the id. */
        public readonly ?string $orderPublicCode = null,
        public readonly ?string $gateway = null,
        public readonly int $amount = 0,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
