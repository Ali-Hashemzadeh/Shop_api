<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HandToPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('shipment.post.hand-over');
    }

    public function rules(): array
    {
        return [
            'tracking_number' => ['required', 'string', 'max:255'],
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'postal_receipt_media_id' => ['nullable', 'integer'],
        ];
    }
}
