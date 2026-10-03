<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ticket\Domain\DTOs\TicketReferenceDTO;

/**
 * @mixin TicketReferenceDTO
 */
class TicketReferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var TicketReferenceDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'type' => $dto->type,
            'reference_id' => $dto->referenceId,
            'reference_code' => $dto->referenceCode,
            'snapshot' => $dto->snapshot,
        ];
    }
}
