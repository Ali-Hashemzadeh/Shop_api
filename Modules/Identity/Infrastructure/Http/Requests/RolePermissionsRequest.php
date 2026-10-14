<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Grant/revoke gate for a role's permission set (`rbac.manage`).
 *
 * Additive by design: the body names the permissions to add (POST) or remove
 * (DELETE); nothing outside the list is ever touched. Every name must exist on
 * the `web` guard — permissions are seeded by the modules, never invented here.
 */
class RolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('rbac.manage');
    }

    public function rules(): array
    {
        return [
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => [
                'required',
                'string',
                'distinct',
                Rule::exists(config('permission.table_names.permissions'), 'name')
                    ->where('guard_name', 'web'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'permissions.required' => 'At least one permission name is required.',
            'permissions.min' => 'At least one permission name is required.',
            'permissions.*.exists' => 'An unknown permission name was supplied.',
            'permissions.*.distinct' => 'The same permission name was supplied more than once.',
        ];
    }
}
