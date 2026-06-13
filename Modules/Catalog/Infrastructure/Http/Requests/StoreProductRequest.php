<?php

namespace Modules\Catalog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'            => ['required', 'string', 'max:255'],
            'slug'             => ['nullable', 'string', 'max:255', 'unique:products,slug'],
            'description'      => ['nullable', 'string'],
            'status'           => ['nullable', 'in:draft,published'],
            'category_id'      => ['nullable', 'integer', 'exists:categories,id'],
            'primary_media_id' => ['nullable', 'integer', 'prohibits:primary_image'],
            'primary_image'    => ['nullable', 'file', 'image', 'max:4096', 'prohibits:primary_media_id'],
            'gallery'          => ['nullable', 'array'],
            'gallery.*'        => ['file', 'image', 'max:4096'],
        ];
    }
}
