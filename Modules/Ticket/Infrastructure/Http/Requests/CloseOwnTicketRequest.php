<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A customer closing their own ticket. Body is empty; the target is the route. */
class CloseOwnTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('ticket.close-own');
    }

    public function rules(): array
    {
        return [];
    }
}
