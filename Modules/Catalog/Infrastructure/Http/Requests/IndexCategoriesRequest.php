<?php

namespace Modules\Catalog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // An exact `bdc-XXXXXX` code (case-insensitive) resolves one category
            // at any depth; anything else is a name/slug contains-match.
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }
}
