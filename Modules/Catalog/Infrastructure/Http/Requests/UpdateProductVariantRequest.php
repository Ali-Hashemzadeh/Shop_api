<?php

namespace Modules\Catalog\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[BodyParameter('variant_image', description: 'Replace the variant image (JPEG/PNG/WebP, max 4 MB). Send as multipart/form-data. Mutually exclusive with media_id.', type: 'string', format: 'binary', required: false, infer: false)]
class UpdateProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('catalog.variant.update');
    }

    protected function prepareForValidation(): void
    {
        $cast = [];

        foreach (['base_price', 'compare_at_price'] as $field) {
            $value = $this->input($field);
            if (is_string($value) && preg_match('/^\d+$/', $value)) {
                $cast[$field] = (int) $value;
            }
        }

        if ($cast !== []) {
            $this->merge($cast);
        }
    }

    public function rules(): array
    {
        return [
            'sku' => ['sometimes', 'string', 'max:255', Rule::unique('product_variants', 'sku')->ignore($this->route('variantId'))],
            'type' => ['sometimes', 'in:image,color'],
            'base_price' => ['sometimes', 'integer', 'min:0'],
            'compare_at_price' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['sometimes', 'boolean'],
            'media_id' => ['nullable', 'integer', 'prohibits:variant_image'],
            'variant_image' => ['nullable', 'file', 'image', 'max:4096', 'prohibits:media_id'],
            'attributes' => ['sometimes', 'array'],
        ];
    }
}
