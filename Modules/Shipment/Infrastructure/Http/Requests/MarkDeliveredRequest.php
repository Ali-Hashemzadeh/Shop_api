<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkDeliveredRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('shipment.delivery.complete');
    }

    public function rules(): array
    {
        return [
            // Optional at the validation layer only because this endpoint also
            // closes pickup/postal shipments, which have no code. For a local
            // delivery the action requires it — an admin gets no code-free bypass.
            'code' => ['nullable', 'string', 'max:16'],
            'receiver_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'proof_media_id' => ['nullable', 'integer'],
        ];
    }
}
