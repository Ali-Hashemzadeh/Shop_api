<?php

declare(strict_types=1);

namespace Modules\Payment\Domain\Events;

/**
 * Published integration event: server-side gateway verification rejected the
 * payment. Dispatched only from the verification-failure branch — not when the
 * customer simply abandons the gateway page. Carries primitives only.
 */
class PaymentFailedEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
    ) {}
}
