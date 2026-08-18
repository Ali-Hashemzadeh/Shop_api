<?php

declare(strict_types=1);

namespace Modules\Payment\Domain\Events;

use Illuminate\Support\Str;

/**
 * Published integration event: a payment attempt was cancelled or expired.
 *
 * Carries primitives only.
 */
class PaymentCancelledEvent
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        public readonly string $gateway,
        public readonly int $amount = 0,
        public readonly ?int $paymentId = null,
        public readonly ?string $paymentPublicCode = null,
        public readonly ?string $orderPublicCode = null,
        public readonly ?string $cancelledAt = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
