<?php

declare(strict_types=1);

namespace Modules\Payment\Infrastructure\Validation;

use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Payment\Domain\Models\Payment;
use Modules\Ticket\Domain\Contracts\TicketReferenceValidatorInterface;

/**
 * Ownership check for a payment public code. A payment carries no `user_id` of
 * its own — ownership flows from its order — so this resolves the payment's
 * `order_id` from Payment's own table and defers the ownership decision to the
 * Order contract (Payment already depends on Order). No cross-module table join,
 * no Order model import. Registered under `ticket.reference_validators`.
 */
class PaymentTicketReferenceValidator implements TicketReferenceValidatorInterface
{
    public function __construct(
        private readonly OrderManagerInterface $orders,
    ) {}

    public function type(): string
    {
        return 'payment';
    }

    public function ownedByUser(string $code, int $userId): bool
    {
        $orderId = Payment::query()
            ->where('public_code', $code)
            ->value('order_id');

        if ($orderId === null) {
            return false;
        }

        return $this->orders->findOrder((int) $orderId)?->userId === $userId;
    }
}
