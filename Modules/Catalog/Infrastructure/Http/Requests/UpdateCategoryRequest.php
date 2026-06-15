<?php

namespace Modules\Catalog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('catalog.category.update');
    }

    public function rules(): array
    {
        return [
            'name'      => ['sometimes', 'string', 'max:255'],
            'slug'      => ['sometimes', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($this->route('id'))],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'is_active' => ['sometimes', 'boolean'],
            'media_id'  => ['nullable', 'integer', 'prohibits:image'],
            'image'     => ['nullable', 'file', 'image', 'max:4096', 'prohibits:media_id'],
        ];
    }
}
