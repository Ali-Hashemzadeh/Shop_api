<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Admin creating a ticket category. */
class StoreTicketCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('ticket.manage-categories');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/', Rule::unique('ticket_categories', 'code')],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'The code may contain only lowercase letters, numbers, underscores and hyphens.',
        ];
    }
}
