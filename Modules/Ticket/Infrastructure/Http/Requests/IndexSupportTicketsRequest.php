<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ticket\Domain\Enums\TicketPriority;
use Modules\Ticket\Domain\Enums\TicketStatus;

/**
 * A support agent's queue of assigned tickets. Also gates the support detail
 * endpoint (same permission). A support agent only ever sees tickets scoped to
 * their own assignments — the controller enforces that structurally.
 */
class IndexSupportTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('ticket.view-assigned');
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(TicketStatus::values())],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(TicketPriority::values())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
