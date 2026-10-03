<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Contracts;

use Modules\Ticket\Domain\Enums\TicketReferenceType;

/**
 * A published contract the Ticket module offers to any module that owns an
 * entity a ticket may reference (Order, Payment, Shipment, …).
 *
 * Ticket must not import those modules' models, so it cannot decide by itself
 * whether a quoted code (`bdo-XXXXXX`, `bdt-XXXXXX`, …) actually belongs to the
 * customer opening the ticket. Instead each owning module implements this
 * interface against **its own tables** and registers it under the container tag
 * `ticket.reference_validators`; Ticket resolves the tagged collection at
 * validation time and asks the matching validator.
 *
 * Reference types with no registered validator (e.g. `product`/`variant`, which
 * are public and owned by nobody) are treated as informational and accepted —
 * the loose-reference philosophy is preserved for unowned types, while owned
 * types are ownership-gated.
 */
interface TicketReferenceValidatorInterface
{
    /**
     * The reference type this validator owns — a value of
     * {@see TicketReferenceType} (e.g. `order`).
     */
    public function type(): string;

    /**
     * True when a record with this public code exists **and** belongs to the
     * given user. A code that does not exist, or belongs to someone else, must
     * return false so the reference is rejected. Never leaks the owning model.
     */
    public function ownedByUser(string $code, int $userId): bool;
}
