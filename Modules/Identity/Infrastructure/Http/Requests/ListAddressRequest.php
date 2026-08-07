<?php

namespace Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Exact `bda-XXXXXX` address code (case-insensitive). Always applied on
            // top of the caller's own-address scope.
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }
}
