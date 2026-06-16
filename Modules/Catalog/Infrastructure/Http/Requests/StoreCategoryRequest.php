<?php

namespace Modules\Catalog\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

#[BodyParameter('image', description: 'Category banner image (JPEG/PNG/WebP, max 4 MB). Send as multipart/form-data. Mutually exclusive with media_id.', type: 'string', format: 'binary', required: false, infer: false)]
class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('catalog.category.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:categories,slug'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'is_active' => ['nullable', 'boolean'],
            'media_id' => ['nullable', 'integer', 'prohibits:image'],
            'image' => ['nullable', 'file', 'image', 'max:4096', 'prohibits:media_id'],
        ];
    }
}
