<?php

declare(strict_types=1);

namespace Modules\Payment\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitializePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('payment.create');
    }

    public function rules(): array
    {
        return [
            'order_id' => ['required', 'integer'],
            'method_type' => ['required', 'string', 'in:online,in_person'],
            'gateway' => ['nullable', 'string'],
            // Only the CODE is accepted. The discount amount, the final total, and
            // the percentage are all computed server-side by the Order module — a
            // client-supplied figure would be a trivial way to underpay.
            'coupon_code' => ['nullable', 'string', 'max:32'],
        ];
    }
}
