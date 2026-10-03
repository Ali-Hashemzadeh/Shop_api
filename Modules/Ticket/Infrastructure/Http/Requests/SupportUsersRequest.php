<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorize-only gate for the admin support-user surface (`ticket.manage-support-users`):
 * listing agents and granting/revoking the support role. The target user is the
 * route parameter; there is no body.
 */
class SupportUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('ticket.manage-support-users');
    }

    public function rules(): array
    {
        return [];
    }
}
