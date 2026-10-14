<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Read gate for one role and its permission set (`rbac.view`). The target is the
 * route parameter; there is no body.
 */
class ShowRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('rbac.view');
    }

    public function rules(): array
    {
        return [];
    }
}
