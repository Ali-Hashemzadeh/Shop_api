<?php

declare(strict_types=1);

namespace Modules\Cart\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku'      => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('quantity')) {
            $this->merge(['quantity' => (int) $this->input('quantity')]);
        }
    }
}
