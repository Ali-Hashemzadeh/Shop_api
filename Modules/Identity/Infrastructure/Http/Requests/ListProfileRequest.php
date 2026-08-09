<?php

namespace Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            // Role filter — how an admin picks a driver to assign a delivery to.
            'role' => ['sometimes', 'string', 'in:admin,customer,delivery'],
        ];
    }
}
