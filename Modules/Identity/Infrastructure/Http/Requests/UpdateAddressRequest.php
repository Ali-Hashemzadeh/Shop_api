<?php

namespace Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'province_id' => ['sometimes', 'nullable', 'integer', Rule::exists('provinces', 'id')],
            'city_id' => [
                'sometimes',
                'integer',
                Rule::exists('cities', 'id')->where(function ($query) {
                    $query->where('province_id', $this->input('province_id'));
                }),
            ],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'string', 'max:1000'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'map_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_default_shipping' => ['sometimes', 'boolean'],
        ];
    }
}
