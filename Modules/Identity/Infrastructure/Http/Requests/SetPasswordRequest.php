<?php

namespace Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user may set a password on their own account.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // 'confirmed' requires a matching `password_confirmation` field.
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }
}
