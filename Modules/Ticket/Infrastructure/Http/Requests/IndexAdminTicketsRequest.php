<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ticket\Domain\Enums\TicketPriority;
use Modules\Ticket\Domain\Enums\TicketStatus;

/**
 * Admin listing of every ticket, with optional filters. Also gates the admin
 * detail endpoint (same permission).
 */
class IndexAdminTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('ticket.view-admin');
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(TicketStatus::values())],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(TicketPriority::values())],
            'category' => ['sometimes', 'nullable', 'string'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
