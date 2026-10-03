<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Ticket\Infrastructure\Http\Requests\Concerns\ValidatesMediaOwnership;

/**
 * A staff reply visible to the customer, optionally with attachments. Shared by
 * the support and admin surfaces — both hold `ticket.reply-admin`; only the row
 * scoping (assignee vs. any ticket) differs, and that lives in the controller.
 * Attachments must belong to the acting staff member (same ownership rule).
 */
class StaffReplyRequest extends FormRequest
{
    use ValidatesMediaOwnership;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('ticket.reply-admin');
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string'],
            ...$this->mediaIdsRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateMediaOwnership($validator));
    }
}
