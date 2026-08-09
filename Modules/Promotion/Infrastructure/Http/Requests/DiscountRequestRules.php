<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Modules\Promotion\Domain\Enums\DiscountScope;
use Modules\Promotion\Domain\Enums\DiscountTargetType;
use Modules\Promotion\Domain\Enums\DiscountTriggerType;
use Modules\Promotion\Domain\Enums\DiscountType;

/**
 * Validation shared by discount create and update.
 *
 * Kept in one place because the two shapes an automatic and a coupon rule may take
 * are the module's central invariant: divergence between the create and update
 * rules is exactly how a coupon discount would end up with targets, or an
 * automatic one with scope=all — and either would be a live pricing bug.
 */
final class DiscountRequestRules
{
    /**
     * Cast whole-number strings to int before the `integer` rule fires, so
     * form-encoded requests satisfy the Cents Rule the same way Catalog's product
     * requests do.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function castMoneyFields(array $input): array
    {
        $cast = [];

        foreach (['percentage_bps', 'fixed_amount', 'max_discount_amount', 'min_subtotal', 'priority'] as $field) {
            $value = $input[$field] ?? null;

            if (is_string($value) && preg_match('/^\d+$/', $value)) {
                $cast[$field] = (int) $value;
            }
        }

        return $cast;
    }

    /** @return array<string, array<int, string>> */
    public static function rules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'trigger_type' => [$presence, 'string', 'in:'.implode(',', array_column(DiscountTriggerType::cases(), 'value'))],
            'scope' => [$presence, 'string', 'in:'.implode(',', array_column(DiscountScope::cases(), 'value'))],
            'discount_type' => [$presence, 'string', 'in:'.implode(',', array_column(DiscountType::cases(), 'value'))],
            // 10000 bps == 100%. Anything above would imply paying the customer.
            'percentage_bps' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'fixed_amount' => ['nullable', 'integer', 'min:1'],
            'max_discount_amount' => ['nullable', 'integer', 'min:1'],
            'min_subtotal' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer'],
            'targets' => ['nullable', 'array'],
            'targets.*.target_type' => ['required', 'string', 'in:'.implode(',', array_column(DiscountTargetType::cases(), 'value'))],
            // Loose Catalog reference: a positive integer is all that is checked.
            // Promotion deliberately does not verify the row exists — that would
            // require calling Catalog, which already depends on Promotion.
            'targets.*.target_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Enforce the trigger/scope/target/value invariants.
     *
     * @param  array<string, mixed>  $input
     * @param  bool  $partial  On update, only checks the keys the caller actually sent.
     */
    public static function assertShape(Validator $v, array $input, bool $partial = false): void
    {
        $trigger = $input['trigger_type'] ?? null;
        $scope = $input['scope'] ?? null;
        $type = $input['discount_type'] ?? null;
        $targets = $input['targets'] ?? null;

        // ── Value shape ───────────────────────────────────────────────────────
        if ($type === DiscountType::PERCENTAGE->value) {
            if (empty($input['percentage_bps'])) {
                $v->errors()->add('percentage_bps', 'A percentage discount requires percentage_bps.');
            }

            if (! empty($input['fixed_amount'])) {
                $v->errors()->add('fixed_amount', 'A percentage discount must not set fixed_amount.');
            }
        }

        if ($type === DiscountType::FIXED_AMOUNT->value) {
            if (empty($input['fixed_amount'])) {
                $v->errors()->add('fixed_amount', 'A fixed-amount discount requires fixed_amount.');
            }

            if (! empty($input['percentage_bps'])) {
                $v->errors()->add('percentage_bps', 'A fixed-amount discount must not set percentage_bps.');
            }
        }

        // ── Automatic: targeted, with at least one target ─────────────────────
        if ($trigger === DiscountTriggerType::AUTOMATIC->value) {
            if ($scope !== null && $scope !== DiscountScope::TARGETED->value) {
                $v->errors()->add('scope', 'An automatic discount must use scope=targeted. Store-wide automatic discounts are not supported.');
            }

            // On create the targets key is mandatory; on update it may be omitted to
            // leave the existing reach untouched, but an explicit empty list is still
            // rejected — a targeted rule with nothing to target is dead configuration.
            if (! $partial && (! is_array($targets) || $targets === [])) {
                $v->errors()->add('targets', 'An automatic discount must target at least one product, variant, category, or brand.');
            }

            if ($partial && is_array($targets) && $targets === []) {
                $v->errors()->add('targets', 'An automatic discount must target at least one product, variant, category, or brand.');
            }

            // Coupon-only knobs are rejected rather than quietly ignored, so an
            // operator is never left believing a spend threshold is in force.
            if (! empty($input['min_subtotal'])) {
                $v->errors()->add('min_subtotal', 'min_subtotal applies to coupon discounts only.');
            }
        }

        // ── Coupon: store-wide, with no targets ───────────────────────────────
        if ($trigger === DiscountTriggerType::COUPON->value) {
            if ($scope !== null && $scope !== DiscountScope::ALL->value) {
                $v->errors()->add('scope', 'A coupon discount must use scope=all.');
            }

            if (! empty($targets)) {
                $v->errors()->add('targets', 'A coupon discount cannot have product, variant, category, or brand targets.');
            }
        }
    }
}
