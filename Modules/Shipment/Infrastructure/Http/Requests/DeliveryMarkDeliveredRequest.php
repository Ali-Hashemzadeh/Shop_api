<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The courier's completion request. `code` is required here — a driver route
 * that accepted a missing code would be a way around the customer's confirmation.
 */
class DeliveryMarkDeliveredRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('shipment.delivery.complete-assigned');
    }

    public function rules(): array
    {
        return [
            // Length is not validated against the configured code length: telling a
            // guesser their code was the wrong *shape* is already a hint. Anything
            // that is not the current code fails the same way.
            'code' => ['required', 'string', 'max:16'],
            'receiver_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
