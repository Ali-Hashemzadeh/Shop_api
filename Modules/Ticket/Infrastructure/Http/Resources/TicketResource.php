<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ticket\Domain\DTOs\TicketDTO;

/**
 * Ticket shape. Accepts the DTO only, never the model. `messages` and
 * `references` appear only on the detail view (they are null on list rows), and
 * the DTO's messages are already visibility-filtered by the query layer — an
 * internal note never reaches a customer through this resource.
 *
 * @mixin TicketDTO
 */
class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var TicketDTO $dto */
        $dto = $this->resource;

        return [
            'ticket_number' => $dto->ticketNumber,
            'user_id' => $dto->userId,
            'assigned_to' => $dto->assignedTo,
            'subject' => $dto->subject,
            'category' => $dto->category,
            'priority' => $dto->priority,
            'status' => $dto->status,
            'last_message_at' => $dto->lastMessageAt?->toISOString(),
            'closed_at' => $dto->closedAt?->toISOString(),
            'created_at' => $dto->createdAt->toISOString(),
            'updated_at' => $dto->updatedAt->toISOString(),
            'messages' => $dto->messages === null
                ? null
                : TicketMessageResource::collection($dto->messages),
            'references' => $dto->references === null
                ? null
                : TicketReferenceResource::collection($dto->references),
        ];
    }
}
