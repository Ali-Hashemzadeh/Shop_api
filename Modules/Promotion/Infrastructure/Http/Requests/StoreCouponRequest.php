<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Promotion\Domain\Models\Coupon;

class StoreCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('promotion.coupon.manage');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            // Normalize before the unique rule runs, so "summer10" cannot slip past
            // an existing "SUMMER10".
            $this->merge(['code' => Coupon::normalizeCode($this->input('code'))]);
        }
    }

    public function rules(): array
    {
        return [
            'discount_id' => ['required', 'integer', 'exists:discounts,id'],
            // Marketing alphabet: letters, digits, dash, underscore. Deliberately not
            // a PublicCodeGenerator code — customers are given these to type.
            // Uniqueness ignores soft-deletes so a retired code is never reissued.
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', 'unique:coupons,code'],
            'is_active' => ['nullable', 'boolean'],
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
