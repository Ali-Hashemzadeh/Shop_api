<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Persistence\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Media\Domain\Contracts\MediaManagerInterface;
use Modules\Media\Domain\DTOs\MediaDTO;
use Modules\Ticket\Domain\Contracts\TicketManagerInterface;
use Modules\Ticket\Domain\DTOs\TicketDTO;
use Modules\Ticket\Domain\DTOs\TicketMessageDTO;
use Modules\Ticket\Domain\DTOs\TicketReferenceDTO;
use Modules\Ticket\Domain\Enums\TicketMessageType;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketMessage;

class EloquentTicketManager implements TicketManagerInterface
{
    public function __construct(
        private readonly MediaManagerInterface $media,
    ) {}

    public function paginateForCustomer(int $userId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->paginate(
            Ticket::query()->where('user_id', $userId),
            $filters,
            $perPage,
        );
    }

    public function paginateForAssignee(int $supportUserId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->paginate(
            Ticket::query()->where('assigned_to', $supportUserId),
            $filters,
            $perPage,
        );
    }

    public function paginateForAdmin(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->paginate(Ticket::query(), $filters, $perPage);
    }

    public function findForCustomer(string $ticketNumber, int $userId): ?TicketDTO
    {
        $ticket = Ticket::query()
            ->where('ticket_number', $ticketNumber)
            ->where('user_id', $userId)
            ->first();

        return $ticket === null ? null : $this->hydrateDetail($ticket, includeInternalNotes: false);
    }

    public function findForAssignee(string $ticketNumber, int $supportUserId): ?TicketDTO
    {
        $ticket = Ticket::query()
            ->where('ticket_number', $ticketNumber)
            ->where('assigned_to', $supportUserId)
            ->first();

        return $ticket === null ? null : $this->hydrateDetail($ticket, includeInternalNotes: true);
    }

    public function findForStaff(string $ticketNumber): ?TicketDTO
    {
        $ticket = Ticket::query()
            ->where('ticket_number', $ticketNumber)
            ->first();

        return $ticket === null ? null : $this->hydrateDetail($ticket, includeInternalNotes: true);
    }

    /**
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, TicketDTO>
     */
    private function paginate(Builder $query, array $filters, int $perPage): LengthAwarePaginator
    {
        $perPage = max(1, min(100, $perPage));

        foreach (['status', 'priority', 'category'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_to', (int) $filters['assigned_to']);
        }

        // Most recently active first — the queue a human works top-down.
        $query->orderByRaw('COALESCE(last_message_at, created_at) DESC');

        /** @var LengthAwarePaginator<int, Ticket> $paginator */
        $paginator = $query->paginate($perPage)->withQueryString();

        return $paginator->through(fn (Ticket $ticket): TicketDTO => TicketDTO::fromModel($ticket));
    }

    private function hydrateDetail(Ticket $ticket, bool $includeInternalNotes): TicketDTO
    {
        $messagesQuery = $ticket->messages()->orderBy('created_at')->orderBy('id');

        if (! $includeInternalNotes) {
            $messagesQuery->whereIn('type', TicketMessageType::customerVisible());
        }

        $messages = $messagesQuery->get();

        // Batch-resolve every attachment across the whole conversation in one
        // Media call (no N+1), then map each MediaDTO back onto its message.
        $mediaById = $this->resolveAttachments(
            $messages->flatMap(fn (TicketMessage $message) => $message->media_ids ?? [])->all()
        );

        $messages = $messages
            ->map(fn (TicketMessage $message) => TicketMessageDTO::fromModel(
                $message,
                $this->pickAttachments($message->media_ids ?? [], $mediaById),
            ))
            ->values()
            ->all();

        $references = $ticket->references()->orderBy('id')->get()
            ->map(fn ($reference) => TicketReferenceDTO::fromModel($reference))
            ->values()
            ->all();

        return TicketDTO::fromModel($ticket, $messages, $references);
    }

    /**
     * Resolve a flat list of media ids into a map id → MediaDTO, in one batch
     * call. Missing ids simply don't appear in the map.
     *
     * @param  array<int|string>  $mediaIds
     * @return array<int, MediaDTO>
     */
    private function resolveAttachments(array $mediaIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $mediaIds)));

        if ($ids === []) {
            return [];
        }

        $map = [];

        foreach ($this->media->getMediaCollection($ids) as $dto) {
            $map[$dto->id] = $dto;
        }

        return $map;
    }

    /**
     * Pick this message's attachments out of the batch map, preserving the
     * stored order and dropping any id that no longer resolves.
     *
     * @param  array<int|string>  $mediaIds
     * @param  array<int, MediaDTO>  $mediaById
     * @return list<MediaDTO>
     */
    private function pickAttachments(array $mediaIds, array $mediaById): array
    {
        $attachments = [];

        foreach ($mediaIds as $id) {
            $id = (int) $id;

            if (isset($mediaById[$id])) {
                $attachments[] = $mediaById[$id];
            }
        }

        return $attachments;
    }
}
