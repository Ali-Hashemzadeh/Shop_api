<?php

namespace Modules\Catalog\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[BodyParameter('image', description: 'Replace the brand logo image (JPEG/PNG/WebP, max 4 MB). Send as multipart/form-data. Mutually exclusive with media_id.', type: 'string', format: 'binary', required: false, infer: false)]
class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('catalog.brand.update');
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('brands', 'slug')->ignore($this->route('id'))],
            'is_active' => ['sometimes', 'boolean'],
            'media_id' => ['nullable', 'integer', 'prohibits:image'],
            'image' => ['nullable', 'file', 'image', 'max:4096', 'prohibits:media_id'],
        ];
    }
}
