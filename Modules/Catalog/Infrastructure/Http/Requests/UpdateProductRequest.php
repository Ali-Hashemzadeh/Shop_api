<?php

namespace Modules\Catalog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('catalog.product.update');
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
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($this->route('id'))],
            'description' => ['nullable', 'string'],
            'features' => ['nullable', 'array'],
            'features.*.title' => ['required', 'string', 'max:255'],
            'features.*.value' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', 'in:draft,published'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'primary_media_id' => ['nullable', 'integer'],
            'variants' => ['nullable', 'array', 'min:1'],
            'variants.*.sku' => ['required', 'string', 'max:255', 'distinct'],
            'variants.*.base_price' => ['required', 'integer', 'min:0'],
            'variants.*.compare_at_price' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_default' => ['required', 'boolean'],
            'variants.*.media_id' => ['nullable', 'integer'],
            'variants.*.attributes' => ['nullable', 'array'],
        ];
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Contracts\Validation\Validator $v) {
            $variants = $this->input('variants');

            if (! is_array($variants) || count($variants) === 0) {
                return;
            }

            $defaultCount = collect($variants)
                ->filter(fn ($variant) => ! empty($variant['is_default']))
                ->count();

            if ($defaultCount > 1) {
                $v->errors()->add(
                    'variants',
                    'At most one variant may be marked as is_default.'
                );
            }
        });
    }
}
