<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\DTOs;

use Modules\Ticket\Domain\Models\TicketCategory;

/**
 * Immutable view of one ticket category crossing the boundary.
 */
class TicketCategoryDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $code,
        public readonly bool $isActive,
        public readonly int $sortOrder,
    ) {}

    public static function fromModel(TicketCategory $category): self
    {
        return new self(
            id: (int) $category->id,
            name: (string) $category->name,
            code: (string) $category->code,
            isActive: (bool) $category->is_active,
            sortOrder: (int) $category->sort_order,
        );
    }
}
