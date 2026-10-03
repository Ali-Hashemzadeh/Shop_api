<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Validation;

use Modules\Shipment\Domain\Models\Shipment;
use Modules\Ticket\Domain\Contracts\TicketReferenceValidatorInterface;

/**
 * Ownership check for a shipment public code, answered from Shipment's own table
 * (`shipments.user_id`). Registered under `ticket.reference_validators` by
 * ShipmentServiceProvider so Ticket can gate shipment references without importing
 * the Shipment model.
 */
class ShipmentTicketReferenceValidator implements TicketReferenceValidatorInterface
{
    public function type(): string
    {
        return 'shipment';
    }

    public function ownedByUser(string $code, int $userId): bool
    {
        return Shipment::query()
            ->where('public_code', $code)
            ->where('user_id', $userId)
            ->exists();
    }
}
