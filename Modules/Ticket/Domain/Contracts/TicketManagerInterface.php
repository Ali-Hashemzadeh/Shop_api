<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Ticket\Domain\DTOs\TicketDTO;

/**
 * The Ticket module's read surface for its own HTTP layer. Nothing outside the
 * module consumes it today, but keeping the contract/implementation split
 * mirrors every other module and isolates the query layer from the controllers.
 *
 * Detail methods hydrate `messages` + `references`. The customer variant filters
 * internal notes out at the query layer so they can never leak; the staff
 * variant returns the full conversation. Returns null when the ticket does not
 * exist or is out of the caller's scope, so ownership can never be bypassed by
 * guessing a ticket number.
 */
interface TicketManagerInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, TicketDTO>
     */
    public function paginateForCustomer(int $userId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, TicketDTO>
     */
    public function paginateForAssignee(int $supportUserId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, TicketDTO>
     */
    public function paginateForAdmin(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /** Detail scoped to the owning customer, internal notes excluded. */
    public function findForCustomer(string $ticketNumber, int $userId): ?TicketDTO;

    /** Detail scoped to the assigned support user, full conversation. */
    public function findForAssignee(string $ticketNumber, int $supportUserId): ?TicketDTO;

    /** Detail for any ticket (admin), full conversation. */
    public function findForStaff(string $ticketNumber): ?TicketDTO;
}
