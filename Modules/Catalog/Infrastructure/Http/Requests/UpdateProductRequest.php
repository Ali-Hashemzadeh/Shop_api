<?php

namespace Modules\Catalog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'            => ['sometimes', 'string', 'max:255'],
            'slug'             => ['sometimes', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($this->route('id'))],
            'description'      => ['nullable', 'string'],
            'status'           => ['sometimes', 'in:draft,published'],
            'category_id'      => ['nullable', 'integer', 'exists:categories,id'],
            'primary_media_id' => ['nullable', 'integer', 'prohibits:primary_image'],
            'primary_image'    => ['nullable', 'file', 'image', 'max:4096', 'prohibits:primary_media_id'],
        ];
    }
}
