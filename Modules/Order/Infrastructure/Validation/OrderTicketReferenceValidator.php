<?php

declare(strict_types=1);

namespace Modules\Order\Infrastructure\Validation;

use Modules\Order\Domain\Models\Order;
use Modules\Ticket\Domain\Contracts\TicketReferenceValidatorInterface;

/**
 * Answers "does this order public code belong to this user?" for the Ticket
 * module's reference-ownership gate — from Order's own table only, so Ticket
 * never touches the Order model. Registered under the `ticket.reference_validators`
 * container tag by OrderServiceProvider.
 */
class OrderTicketReferenceValidator implements TicketReferenceValidatorInterface
{
    public function type(): string
    {
        return 'order';
    }

    public function ownedByUser(string $code, int $userId): bool
    {
        return Order::query()
            ->where('public_code', $code)
            ->where('user_id', $userId)
            ->exists();
    }
}
