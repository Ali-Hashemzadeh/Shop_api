<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Media\Infrastructure\Http\Resources\MediaResource;
use Modules\Ticket\Domain\DTOs\TicketMessageDTO;

/**
 * @mixin TicketMessageDTO
 */
class TicketMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var TicketMessageDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'user_id' => $dto->userId,
            'message' => $dto->message,
            'type' => $dto->type,
            // Reuse the existing Media resource — no bespoke attachment resource.
            'attachments' => MediaResource::collection($dto->attachments),
            'created_at' => $dto->createdAt->toISOString(),
        ];
    }
}
