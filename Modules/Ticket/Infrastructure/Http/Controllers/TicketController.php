<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Ticket\Application\Actions\CloseTicketAction;
use Modules\Ticket\Application\Actions\CreateTicketAction;
use Modules\Ticket\Application\Actions\ReplyToTicketAction;
use Modules\Ticket\Domain\Contracts\TicketManagerInterface;
use Modules\Ticket\Domain\Enums\TicketPriority;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Infrastructure\Http\Requests\CloseOwnTicketRequest;
use Modules\Ticket\Infrastructure\Http\Requests\IndexTicketsRequest;
use Modules\Ticket\Infrastructure\Http\Requests\ReplyTicketRequest;
use Modules\Ticket\Infrastructure\Http\Requests\StoreTicketRequest;
use Modules\Ticket\Infrastructure\Http\Resources\TicketMessageResource;
use Modules\Ticket\Infrastructure\Http\Resources\TicketResource;

/**
 * The customer-facing ticket surface. Every read and write is structurally
 * scoped to the authenticated user's own tickets — a foreign ticket number
 * resolves to 404 (never revealing that it exists), and internal notes are
 * filtered out at the query layer.
 */
class TicketController extends Controller
{
    public function __construct(
        private readonly TicketManagerInterface $tickets,
    ) {}

    public function index(IndexTicketsRequest $request): JsonResponse
    {
        $paginator = $this->tickets->paginateForCustomer(
            userId: $this->userId($request),
            filters: ['status' => $request->validated('status')],
            perPage: (int) $request->validated('per_page', 15),
        );

        return response()->json(
            TicketResource::collection($paginator)->response()->getData(true)
        );
    }

    public function store(StoreTicketRequest $request, CreateTicketAction $action): JsonResponse
    {
        $userId = $this->userId($request);

        $references = array_map(
            static fn (array $reference): array => [
                'type' => $reference['type'],
                'code' => $reference['code'] ?? null,
                'id' => null,
            ],
            (array) $request->validated('references', []),
        );

        $ticket = $action->handle(
            userId: $userId,
            subject: (string) $request->validated('subject'),
            category: $request->validated('category'),
            priority: TicketPriority::from((string) $request->validated('priority')),
            message: (string) $request->validated('message'),
            references: $references,
            mediaIds: array_map('intval', (array) $request->validated('media_ids', [])),
        );

        return response()->json(
            ['data' => new TicketResource($this->tickets->findForCustomer((string) $ticket->ticket_number, $userId))],
            201,
        );
    }

    public function show(IndexTicketsRequest $request, string $ticketNumber): JsonResponse
    {
        $dto = $this->tickets->findForCustomer($ticketNumber, $this->userId($request));

        abort_if($dto === null, 404);

        return response()->json(['data' => new TicketResource($dto)]);
    }

    public function reply(ReplyTicketRequest $request, string $ticketNumber, ReplyToTicketAction $action): JsonResponse
    {
        $userId = $this->userId($request);
        $ticket = $this->ownedTicketOrFail($ticketNumber, $userId);

        if ($ticket->status instanceof TicketStatus && $ticket->status->isTerminal()) {
            throw ValidationException::withMessages([
                'message' => ['This ticket is closed. Please open a new ticket.'],
            ]);
        }

        $message = $action->handle(
            $ticket,
            $userId,
            (string) $request->validated('message'),
            fromStaff: false,
            mediaIds: array_map('intval', (array) $request->validated('media_ids', [])),
        );

        return response()->json(['data' => new TicketMessageResource($message)], 201);
    }

    public function close(CloseOwnTicketRequest $request, string $ticketNumber, CloseTicketAction $action): JsonResponse
    {
        $userId = $this->userId($request);
        $ticket = $this->ownedTicketOrFail($ticketNumber, $userId);

        $action->handle($ticket, $userId);

        return response()->json(['data' => new TicketResource($this->tickets->findForCustomer($ticketNumber, $userId))]);
    }

    private function userId(IndexTicketsRequest|ReplyTicketRequest|CloseOwnTicketRequest|StoreTicketRequest $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }

    private function ownedTicketOrFail(string $ticketNumber, int $userId): Ticket
    {
        return Ticket::query()
            ->where('ticket_number', $ticketNumber)
            ->where('user_id', $userId)
            ->firstOrFail();
    }
}
