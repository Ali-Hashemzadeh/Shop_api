<?php

namespace Modules\Catalog\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('catalog.product.create');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('variants'))) {
            $decoded = json_decode($this->input('variants'), true);
            if (is_array($decoded)) {
                $this->merge(['variants' => $decoded]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:products,slug'],
            'description' => ['nullable', 'string'],
            'features' => ['nullable', 'array'],
            'features.*.title' => ['required', 'string', 'max:255'],
            'features.*.value' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,published'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'primary_media_id' => ['nullable', 'integer'],
            'gallery_media_ids' => ['nullable', 'array'],
            'gallery_media_ids.*' => ['integer'],
            'variants' => ['nullable', 'array', 'min:1'],
            'variants.*.sku' => ['required', 'string', 'max:255', 'distinct', 'unique:product_variants,sku'],
            'variants.*.type' => ['required', 'in:image,color'],
            'variants.*.base_price' => ['required', 'integer', 'min:0'],
            'variants.*.compare_at_price' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_default' => ['required', 'boolean'],
            'variants.*.media_id' => ['nullable', 'integer'],
            'variants.*.attributes' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $variants = $this->input('variants');

            if (! is_array($variants) || count($variants) === 0) {
                return;
            }

            $defaultCount = collect($variants)
                ->filter(fn ($variant) => ! empty($variant['is_default']))
                ->count();

            if ($defaultCount !== 1) {
                $v->errors()->add(
                    'variants',
                    'Exactly one variant must be marked as is_default.'
                );
            }
        });
    }
}
