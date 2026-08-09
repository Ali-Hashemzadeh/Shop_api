<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Promotion\Domain\Models\Coupon;

class UpdateCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('promotion.coupon.manage');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Coupon::normalizeCode($this->input('code'))]);
        }
    }

    public function rules(): array
    {
        return [
            'discount_id' => ['sometimes', 'integer', 'exists:discounts,id'],
            'code' => [
                'sometimes',
                'string',
                'max:32',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('coupons', 'code')->ignore((int) $this->route('coupon')),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_user' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'The coupon code may contain only letters, numbers, dashes, and underscores.',
        ];
    }
}
