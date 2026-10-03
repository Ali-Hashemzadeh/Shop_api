<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Ticket\Application\Actions\AddInternalNoteAction;
use Modules\Ticket\Application\Actions\ChangeTicketStatusAction;
use Modules\Ticket\Application\Actions\ReplyToTicketAction;
use Modules\Ticket\Domain\Contracts\TicketManagerInterface;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Infrastructure\Http\Requests\ChangeTicketStatusRequest;
use Modules\Ticket\Infrastructure\Http\Requests\IndexSupportTicketsRequest;
use Modules\Ticket\Infrastructure\Http\Requests\InternalNoteRequest;
use Modules\Ticket\Infrastructure\Http\Requests\StaffReplyRequest;
use Modules\Ticket\Infrastructure\Http\Resources\TicketMessageResource;
use Modules\Ticket\Infrastructure\Http\Resources\TicketResource;

/**
 * The support-agent surface. An agent sees and acts on ONLY the tickets assigned
 * to them: every lookup is scoped by `assigned_to`, so a ticket that is not
 * theirs (or does not exist) resolves to 404 — the same structural scoping the
 * delivery-driver API uses, never a fetch-then-403.
 */
class SupportTicketController extends Controller
{
    public function __construct(
        private readonly TicketManagerInterface $tickets,
    ) {}

    public function index(IndexSupportTicketsRequest $request): JsonResponse
    {
        $paginator = $this->tickets->paginateForAssignee(
            supportUserId: $this->userId($request),
            filters: [
                'status' => $request->validated('status'),
                'priority' => $request->validated('priority'),
            ],
            perPage: (int) $request->validated('per_page', 15),
        );

        return response()->json(
            TicketResource::collection($paginator)->response()->getData(true)
        );
    }

    public function show(IndexSupportTicketsRequest $request, string $ticketNumber): JsonResponse
    {
        $dto = $this->tickets->findForAssignee($ticketNumber, $this->userId($request));

        abort_if($dto === null, 404);

        return response()->json(['data' => new TicketResource($dto)]);
    }

    public function reply(StaffReplyRequest $request, string $ticketNumber, ReplyToTicketAction $action): JsonResponse
    {
        $ticket = $this->assignedTicketOrFail($ticketNumber, $this->userId($request));

        $message = $action->handle(
            $ticket,
            $this->userId($request),
            (string) $request->validated('message'),
            fromStaff: true,
            mediaIds: array_map('intval', (array) $request->validated('media_ids', [])),
        );

        return response()->json(['data' => new TicketMessageResource($message)], 201);
    }

    public function changeStatus(ChangeTicketStatusRequest $request, string $ticketNumber, ChangeTicketStatusAction $action): JsonResponse
    {
        $ticket = $this->assignedTicketOrFail($ticketNumber, $this->userId($request));

        $action->handle($ticket, TicketStatus::from((string) $request->validated('status')), $this->userId($request));

        return response()->json(['data' => new TicketResource($this->tickets->findForAssignee($ticketNumber, $this->userId($request)))]);
    }

    public function addNote(InternalNoteRequest $request, string $ticketNumber, AddInternalNoteAction $action): JsonResponse
    {
        $ticket = $this->assignedTicketOrFail($ticketNumber, $this->userId($request));

        $message = $action->handle(
            $ticket,
            $this->userId($request),
            (string) $request->validated('message'),
            mediaIds: array_map('intval', (array) $request->validated('media_ids', [])),
        );

        return response()->json(['data' => new TicketMessageResource($message)], 201);
    }

    private function userId(IndexSupportTicketsRequest|StaffReplyRequest|ChangeTicketStatusRequest|InternalNoteRequest $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }

    private function assignedTicketOrFail(string $ticketNumber, int $supportUserId): Ticket
    {
        return Ticket::query()
            ->where('ticket_number', $ticketNumber)
            ->where('assigned_to', $supportUserId)
            ->firstOrFail();
    }
}
