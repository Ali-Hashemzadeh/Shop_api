<?php


namespace Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $mode = config('identity.login_field', 'both');

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'device_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'],
        ];

        if ($mode === 'email') {
            $rules['email'][0] = 'required';
        } elseif ($mode === 'phone') {
            $rules['phone'][0] = 'required';
        } else {
            $rules['email'][] = 'required_without:phone';
            $rules['phone'][] = 'required_without:email';
        }

        return $rules;
    }
}
