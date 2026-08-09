<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('promotion.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(DiscountRequestRules::castMoneyFields($this->all()));
    }

    public function rules(): array
    {
        return DiscountRequestRules::rules(required: true);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            DiscountRequestRules::assertShape($v, $this->all());
        });
    }
}
