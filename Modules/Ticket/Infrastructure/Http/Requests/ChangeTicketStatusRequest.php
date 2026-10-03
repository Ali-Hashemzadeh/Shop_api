<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ticket\Domain\Enums\TicketStatus;

/**
 * Change a ticket's status. Shared by support and admin (`ticket.change-status`).
 *
 * The status is normalized to lowercase before validation so a client that sends
 * the constant form (`IN_PROGRESS`) resolves to our stored value (`in_progress`).
 */
class ChangeTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('ticket.change-status');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('status') && is_string($this->input('status'))) {
            $this->merge(['status' => strtolower(trim($this->input('status')))]);
        }
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(TicketStatus::values())],
        ];
    }
}
