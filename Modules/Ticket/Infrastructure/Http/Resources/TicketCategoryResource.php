<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ticket\Domain\DTOs\TicketCategoryDTO;

/**
 * @mixin TicketCategoryDTO
 */
class TicketCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var TicketCategoryDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'name' => $dto->name,
            'code' => $dto->code,
            'is_active' => $dto->isActive,
            'sort_order' => $dto->sortOrder,
        ];
    }
}
