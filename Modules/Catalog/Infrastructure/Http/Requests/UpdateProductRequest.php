<?php

namespace Modules\Catalog\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[BodyParameter('primary_image', description: 'Replace the hero image (JPEG/PNG/WebP, max 4 MB). Send as multipart/form-data. Mutually exclusive with primary_media_id.', type: 'string', format: 'binary', required: false, infer: false)]
class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('catalog.product.update');
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
