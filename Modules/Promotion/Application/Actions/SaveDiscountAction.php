<?php

declare(strict_types=1);

namespace Modules\Promotion\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Promotion\Domain\Contracts\PromotionManagerInterface;
use Modules\Promotion\Domain\DTOs\DiscountDTO;
use Modules\Promotion\Domain\Enums\DiscountScope;
use Modules\Promotion\Domain\Enums\DiscountTriggerType;
use Modules\Promotion\Domain\Models\Discount;
use Modules\Promotion\Domain\Models\DiscountTarget;

/**
 * Create or update a pricing rule together with its targets, atomically.
 *
 * Shape invariants (automatic ⇒ targeted with ≥1 target, coupon ⇒ all with 0
 * targets) are validated in the Form Request so callers get a 422; this action
 * additionally *enforces* the target side of the rule, because a coupon discount
 * that somehow acquired targets would silently start discounting the storefront.
 */
class SaveDiscountAction
{
    public function __construct(
        private readonly PromotionManagerInterface $promotion,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): DiscountDTO
    {
        return DB::transaction(function () use ($data): DiscountDTO {
            $discount = Discount::query()->create($this->attributes($data));

            $this->syncTargets($discount, $data['targets'] ?? []);

            return $this->promotion->findDiscount($discount->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): DiscountDTO
    {
        return DB::transaction(function () use ($id, $data): DiscountDTO {
            $discount = Discount::query()->findOrFail($id);
            $discount->update($this->attributes($data, partial: true));

            // Targets are replaced only when the caller sends them. Omitting the key
            // leaves an existing rule's reach untouched, so a rename cannot
            // accidentally strip every target.
            if (array_key_exists('targets', $data)) {
                $this->syncTargets($discount->fresh(), $data['targets'] ?? []);
            }

            return $this->promotion->findDiscount($discount->id);
        });
    }

    /**
     * Soft-delete. Redemptions, historical orders, and their frozen snapshots are
     * never touched — a deleted rule simply stops being evaluated.
     */
    public function delete(int $id): void
    {
        Discount::query()->findOrFail($id)->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, bool $partial = false): array
    {
        $fields = [
            'name', 'description', 'trigger_type', 'scope', 'discount_type',
            'percentage_bps', 'fixed_amount', 'max_discount_amount', 'min_subtotal',
            'starts_at', 'ends_at', 'is_active', 'priority',
        ];

        $attributes = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            } elseif (! $partial) {
                $attributes[$field] = null;
            }
        }

        if (! $partial) {
            $attributes['is_active'] = $data['is_active'] ?? true;
            $attributes['priority'] = $data['priority'] ?? 0;
        }

        // The unused value column is nulled out explicitly so a rule switched from
        // percentage to fixed (or back) cannot keep a stale figure that a later
        // edit would silently resurrect.
        if (isset($attributes['discount_type'])) {
            if ($attributes['discount_type'] === 'percentage') {
                $attributes['fixed_amount'] = null;
            } elseif ($attributes['discount_type'] === 'fixed_amount') {
                $attributes['percentage_bps'] = null;
            }
        }

        return $attributes;
    }

    /**
     * @param  array<int, array{target_type: string, target_id: int|string}>  $targets
     */
    private function syncTargets(Discount $discount, array $targets): void
    {
        $discount->targets()->delete();

        // A coupon rule is store-wide by definition and must carry no targets —
        // enforced here as well as in validation, since this is the last gate before
        // the rows exist.
        if ($discount->trigger_type === DiscountTriggerType::COUPON || $discount->scope === DiscountScope::ALL) {
            return;
        }

        $rows = [];
        $seen = [];

        foreach ($targets as $target) {
            $type = (string) $target['target_type'];
            $id = (int) $target['target_id'];
            $key = $type.':'.$id;

            // Catalog ids are loose references and are NOT verified to exist — see
            // DiscountTarget. Duplicates are collapsed to respect the unique index.
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = [
                'discount_id' => $discount->id,
                'target_type' => $type,
                'target_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DiscountTarget::query()->insert($rows);
        }
    }
}
