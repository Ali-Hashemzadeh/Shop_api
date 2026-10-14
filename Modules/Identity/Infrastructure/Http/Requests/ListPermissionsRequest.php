<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Read gate for the admin permission catalogue (`rbac.view`). Supports free-text
 * search and an exact namespace filter so pickers can show one module's
 * permissions at a time.
 */
class ListPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('rbac.view');
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:255'],
            // The segment before the first dot, e.g. `catalog` or `rbac`.
            'group' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
