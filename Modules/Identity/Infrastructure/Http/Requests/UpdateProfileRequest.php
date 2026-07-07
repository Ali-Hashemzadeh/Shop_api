<?php

namespace Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Identity\Domain\Models\User;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $routeUser = $this->route('user');
        $targetUser = $routeUser instanceof User ? $routeUser : $this->user();
        $userId = $targetUser?->id;

        $mode = config('identity.login_field', 'both');

        $rules = [
            'name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
                Rule::unique('users', 'phone')->ignore($userId),
            ],
        ];

        if ($mode === 'email') {
            $rules['email'][] = 'required';
        }

        if ($mode === 'phone') {
            $rules['phone'][] = 'required';
        }

        return $rules;
    }
}
