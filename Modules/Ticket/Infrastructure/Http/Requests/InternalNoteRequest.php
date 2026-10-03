<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Ticket\Infrastructure\Http\Requests\Concerns\ValidatesMediaOwnership;

/**
 * A staff-private internal note, optionally with attachments. Shared by support
 * and admin (`ticket.add-internal-note`). Never visible to the customer — and
 * neither are its attachments, since the whole message is filtered out of
 * customer responses. Attachments must belong to the acting staff member.
 */
class InternalNoteRequest extends FormRequest
{
    use ValidatesMediaOwnership;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('ticket.add-internal-note');
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
