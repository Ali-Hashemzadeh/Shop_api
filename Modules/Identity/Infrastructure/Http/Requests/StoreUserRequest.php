<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Admin-created accounts. Two roles are creatable — `customer` and `delivery` —
 * and `admin` deliberately is not: the endpoint exists to onboard shoppers and
 * couriers, never to mint another administrator.
 *
 * No password is ever set here. The account is reachable through the existing
 * OTP flow on its phone number, and the owner may add a password themselves via
 * the authenticated set-password endpoint.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! (bool) $user?->can('profile.create-any')) {
            return false;
        }

        // Creating somebody *as* a delivery worker is an act of granting delivery
        // responsibility, so it needs that permission too. Without this, the
        // create permission alone would be an escalation path around
        // POST /admin/users/{user}/delivery-role.
        if ($this->input('role') === 'delivery') {
            return (bool) $user->can('profile.assign-delivery');
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            // A phone number is mandatory: it is the login credential (OTP) and,
            // for a courier, the number the assignment SMS is sent to.
            'phone' => ['required', 'string', 'regex:/^09\d{9}$/', Rule::unique('users', 'phone')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', 'string', Rule::in(['customer', 'delivery'])],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone number must be a valid 11-digit mobile number starting with 09.',
            'role.in' => 'Only customer and delivery accounts can be created here.',
        ];
    }
}
