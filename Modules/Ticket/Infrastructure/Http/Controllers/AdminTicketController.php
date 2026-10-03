<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Ticket\Application\Actions\AddInternalNoteAction;
use Modules\Ticket\Application\Actions\AssignTicketAction;
use Modules\Ticket\Application\Actions\ChangeTicketStatusAction;
use Modules\Ticket\Application\Actions\ReplyToTicketAction;
use Modules\Ticket\Domain\Contracts\TicketManagerInterface;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Infrastructure\Http\Requests\AssignTicketRequest;
use Modules\Ticket\Infrastructure\Http\Requests\ChangeTicketStatusRequest;
use Modules\Ticket\Infrastructure\Http\Requests\IndexAdminTicketsRequest;
use Modules\Ticket\Infrastructure\Http\Requests\InternalNoteRequest;
use Modules\Ticket\Infrastructure\Http\Requests\StaffReplyRequest;
use Modules\Ticket\Infrastructure\Http\Resources\TicketMessageResource;
use Modules\Ticket\Infrastructure\Http\Resources\TicketResource;

/**
 * The admin surface: every ticket, no scoping. Shares the reply / status / note
 * Actions with the support controller; the only difference is that admin lookups
 * are not restricted to an assignee.
 */
class AdminTicketController extends Controller
{
    public function __construct(
        private readonly TicketManagerInterface $tickets,
    ) {}

    public function index(IndexAdminTicketsRequest $request): JsonResponse
    {
        $paginator = $this->tickets->paginateForAdmin(
            filters: [
                'status' => $request->validated('status'),
                'priority' => $request->validated('priority'),
                'category' => $request->validated('category'),
                'assigned_to' => $request->validated('assigned_to'),
            ],
            perPage: (int) $request->validated('per_page', 15),
        );

        return response()->json(
            TicketResource::collection($paginator)->response()->getData(true)
        );
    }

    public function show(IndexAdminTicketsRequest $request, string $ticketNumber): JsonResponse
    {
        $dto = $this->tickets->findForStaff($ticketNumber);

        abort_if($dto === null, 404);

        return response()->json(['data' => new TicketResource($dto)]);
    }

    public function reply(StaffReplyRequest $request, string $ticketNumber, ReplyToTicketAction $action): JsonResponse
    {
        $ticket = $this->ticketOrFail($ticketNumber);

        $message = $action->handle(
            $ticket,
            $this->userId($request),
            (string) $request->validated('message'),
            fromStaff: true,
            mediaIds: array_map('intval', (array) $request->validated('media_ids', [])),
        );

        return response()->json(['data' => new TicketMessageResource($message)], 201);
    }

    public function addNote(InternalNoteRequest $request, string $ticketNumber, AddInternalNoteAction $action): JsonResponse
    {
        $ticket = $this->ticketOrFail($ticketNumber);

        $message = $action->handle(
            $ticket,
            $this->userId($request),
            (string) $request->validated('message'),
            mediaIds: array_map('intval', (array) $request->validated('media_ids', [])),
        );

        return response()->json(['data' => new TicketMessageResource($message)], 201);
    }

    public function changeStatus(ChangeTicketStatusRequest $request, string $ticketNumber, ChangeTicketStatusAction $action): JsonResponse
    {
        $ticket = $this->ticketOrFail($ticketNumber);

        $action->handle($ticket, TicketStatus::from((string) $request->validated('status')), $this->userId($request));

        return response()->json(['data' => new TicketResource($this->tickets->findForStaff($ticketNumber))]);
    }

    public function assign(AssignTicketRequest $request, string $ticketNumber, AssignTicketAction $action): JsonResponse
    {
        $ticket = $this->ticketOrFail($ticketNumber);

        $action->handle($ticket, (int) $request->validated('user_id'));

        return response()->json(['data' => new TicketResource($this->tickets->findForStaff($ticketNumber))]);
    }

    private function userId(StaffReplyRequest|InternalNoteRequest|ChangeTicketStatusRequest $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }

    private function ticketOrFail(string $ticketNumber): Ticket
    {
        return Ticket::query()->where('ticket_number', $ticketNumber)->firstOrFail();
    }
}
