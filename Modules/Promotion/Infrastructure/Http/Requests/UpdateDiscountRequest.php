<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Promotion\Domain\Models\Discount;

class UpdateDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('promotion.update');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(DiscountRequestRules::castMoneyFields($this->all()));
    }

    public function rules(): array
    {
        return DiscountRequestRules::rules(required: false);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // The shape check judges the rule as it will exist *after* the patch, so
            // the stored row supplies every field the caller omitted. Without this a
            // rename would be rejected for "missing" percentage_bps, and — more
            // importantly — adding a target to an existing coupon rule would slip
            // through, because the request alone carries no trigger_type to check.
            $input = $this->all();
            $discount = Discount::query()->find((int) $this->route('discount'));

            if ($discount !== null) {
                $stored = [
                    'trigger_type' => $discount->trigger_type->value,
                    'scope' => $discount->scope->value,
                    'discount_type' => $discount->discount_type->value,
                    'percentage_bps' => $discount->percentage_bps,
                    'fixed_amount' => $discount->fixed_amount,
                    'min_subtotal' => $discount->min_subtotal,
                ];

                foreach ($stored as $field => $value) {
                    if (! array_key_exists($field, $input)) {
                        $input[$field] = $value;
                    }
                }

                // Switching the value shape clears the other side (SaveDiscountAction
                // nulls it on write), so validation must not judge the outgoing figure.
                if (array_key_exists('discount_type', $this->all())) {
                    $incoming = $this->input('discount_type');

                    if ($incoming === 'percentage' && ! array_key_exists('fixed_amount', $this->all())) {
                        $input['fixed_amount'] = null;
                    }

                    if ($incoming === 'fixed_amount' && ! array_key_exists('percentage_bps', $this->all())) {
                        $input['percentage_bps'] = null;
                    }
                }
            }

            DiscountRequestRules::assertShape($v, $input, partial: true);
        });
    }
}
