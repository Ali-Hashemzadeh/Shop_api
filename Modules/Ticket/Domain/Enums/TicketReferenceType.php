<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Enums;

/**
 * The kind of entity a ticket reference points at.
 *
 * This is an enum-backed whitelist exactly like Review's `subject_type`: a
 * reference is a *loose* pointer (a type + the entity's public code the customer
 * quoted), never a foreign key and never a resolved model. Ticket therefore
 * imports no Order/Payment/Shipment/Catalog model — a reference to a deleted
 * entity is simply inert, and adding a new referable entity is a new case here,
 * never a schema change or a scattered string literal.
 */
enum TicketReferenceType: string
{
    case Order = 'order';
    case Payment = 'payment';
    case Shipment = 'shipment';
    case Product = 'product';
    case Variant = 'variant';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
