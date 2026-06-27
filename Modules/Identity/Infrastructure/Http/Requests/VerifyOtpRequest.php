<?php

namespace Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^09\d{9}$/'],
            'code' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            // Optional registration fields a new user may set while verifying.
            'name' => ['nullable', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone number must be a valid 11-digit mobile number starting with 09.',
        ];
    }
}
