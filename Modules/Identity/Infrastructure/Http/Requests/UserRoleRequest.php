<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Assign/revoke gate for a user's roles (`rbac.manage`).
 *
 * The role name travels in the body — a `{role}` path segment would shadow
 * Ticket's existing `POST/DELETE .../roles/support` routes, which carry their
 * own `ticket.manage-support-users` authorization.
 *
 * `admin` is refused outright: today it is never creatable through the API, and
 * the generic surface must not become the escalation path around that rule.
 */
class UserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('rbac.manage');
    }

    public function rules(): array
    {
        return [
            'role' => [
                'required',
                'string',
                Rule::exists(config('permission.table_names.roles'), 'name')
                    ->where('guard_name', 'web'),
                Rule::notIn(['admin']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'role.exists' => 'The selected role does not exist.',
            'role.not_in' => 'The admin role cannot be assigned or revoked through this endpoint.',
        ];
    }
}
