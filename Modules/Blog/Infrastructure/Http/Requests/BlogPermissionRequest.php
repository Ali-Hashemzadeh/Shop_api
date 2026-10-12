<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for admin requests gated by one or more permissions (any-of). Runs
 * before validation, so a caller without the permission gets 403, never a 422.
 * Subclasses add their own rules.
 */
abstract class BlogPermissionRequest extends FormRequest
{
    /** @return list<string> any one of these permissions authorizes the request */
    abstract protected function permissions(): array;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        foreach ($this->permissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function paginationRules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
