<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Ticket\Infrastructure\Http\Requests\Concerns\ValidatesMediaOwnership;

/** A customer replying to their own ticket, optionally with attachments they own. */
class ReplyTicketRequest extends FormRequest
{
    use ValidatesMediaOwnership;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('ticket.reply-own');
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
