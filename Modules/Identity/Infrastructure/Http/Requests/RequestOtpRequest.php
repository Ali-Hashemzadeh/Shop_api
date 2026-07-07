<?php

namespace Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^09\d{9}$/'],
            // Optional names applied only when the account is created.
            'name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone number must be a valid 11-digit mobile number starting with 09.',
        ];
    }
}
