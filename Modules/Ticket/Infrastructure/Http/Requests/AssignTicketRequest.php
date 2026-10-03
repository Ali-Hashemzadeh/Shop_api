<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;

/**
 * Assign a ticket to a support user.
 *
 * The target must actually hold the `support` role — checked through the Identity
 * contract, never by importing the User model or an `exists:users` rule. A
 * non-existent user reports as "not a support user" too, so a random or customer
 * id is rejected here (422) before the Action runs.
 */
class AssignTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('ticket.assign');
    }

    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! app(IdentityManagerInterface::class)->isSupportUser((int) $value)) {
                        $fail('The selected user is not a support agent.');
                    }
                },
            ],
        ];
    }
}
