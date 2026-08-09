<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('shipment.delivery.assign');
    }

    public function rules(): array
    {
        return [
            // Existence is *not* checked with `exists:users,id` — that would be a
            // Shipment query against Identity's table. Whether the id names a real
            // delivery worker is answered by IdentityManagerInterface inside the
            // action, which also returns 422 on the same field.
            'delivery_user_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
