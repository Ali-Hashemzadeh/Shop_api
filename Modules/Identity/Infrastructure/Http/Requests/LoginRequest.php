<?php

namespace Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $mode = config('identity.login_field', 'both');

        $rules = [
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ];

        if ($mode === 'email') {
            $rules['login'] = ['required', 'email'];
        } else {
            $rules['login'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }
}
