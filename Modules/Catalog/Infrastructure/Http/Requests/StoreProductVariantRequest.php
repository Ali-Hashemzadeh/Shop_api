<?php

namespace Modules\Catalog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $cast = [];

        foreach (['base_price', 'compare_at_price'] as $field) {
            $value = $this->input($field);
            // Cast whole-number strings to int so the Cents Rule guard in
            // CreateProductVariantAction never sees a string.
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
            'sku'              => ['required', 'string', 'max:255', 'unique:product_variants,sku'],
            'base_price'       => ['required', 'integer', 'min:0'],
            'compare_at_price' => ['nullable', 'integer', 'min:0'],
            'is_default'       => ['nullable', 'boolean'],
            'media_id'         => ['nullable', 'integer', 'prohibits:variant_image'],
            'variant_image'    => ['nullable', 'file', 'image', 'max:4096', 'prohibits:media_id'],
            'attributes'       => ['nullable', 'array'],
        ];
    }
}
