<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Read gate for the admin RBAC catalogue (`rbac.view`).
 */
class ListRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('rbac.view');
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
