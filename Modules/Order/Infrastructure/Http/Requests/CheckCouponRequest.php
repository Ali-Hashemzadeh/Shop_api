<?php

declare(strict_types=1);

namespace Modules\Order\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Coupon preview input.
 *
 * No promotion.* permission is required: checking a code against your own order is
 * self-service. Ownership — not a permission — is what protects it, and that is
 * enforced in CheckOrderCouponAction.
 */
class CheckCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated customer may check a code on an order they own.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
        ];
    }
}
