<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateDeliverySlotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('shipment.slot.manage');
    }

    protected function prepareForValidation(): void
    {
        // Form-encoded requests send whole numbers as strings; cast before the
        // integer rule fires, matching how the money fields are handled elsewhere.
        if ($this->filled('days') && is_string($this->input('days')) && ctype_digit($this->input('days'))) {
            $this->merge(['days' => (int) $this->input('days')]);
        }
    }

    public function rules(): array
    {
        return [
            // Omitted → config('shipment.delivery.generation_days'). Capped so a single
            // call cannot generate an unbounded number of sessions.
            'days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ];
    }
}
