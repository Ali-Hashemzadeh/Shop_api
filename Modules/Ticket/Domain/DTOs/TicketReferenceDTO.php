<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\DTOs;

use Modules\Ticket\Domain\Enums\TicketReferenceType;
use Modules\Ticket\Domain\Models\TicketReference;

/**
 * Immutable view of one ticket reference crossing the boundary.
 */
class TicketReferenceDTO
{
    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly ?int $referenceId,
        public readonly ?string $referenceCode,
        public readonly ?array $snapshot,
    ) {}

    public static function fromModel(TicketReference $reference): self
    {
        return new self(
            id: (int) $reference->id,
            type: $reference->reference_type instanceof TicketReferenceType
                ? $reference->reference_type->value
                : (string) $reference->reference_type,
            referenceId: $reference->reference_id !== null ? (int) $reference->reference_id : null,
            referenceCode: $reference->reference_code,
            snapshot: $reference->snapshot,
        );
    }
}
