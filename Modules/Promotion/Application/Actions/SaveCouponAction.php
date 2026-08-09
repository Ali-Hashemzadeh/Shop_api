<?php

declare(strict_types=1);

namespace Modules\Promotion\Application\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Promotion\Domain\Contracts\PromotionManagerInterface;
use Modules\Promotion\Domain\DTOs\CouponDTO;
use Modules\Promotion\Domain\Enums\DiscountScope;
use Modules\Promotion\Domain\Enums\DiscountTriggerType;
use Modules\Promotion\Domain\Models\Coupon;
use Modules\Promotion\Domain\Models\Discount;

/**
 * Create or update a coupon code.
 *
 * The backing discount must be coupon-triggered and store-wide; attaching a code
 * to an automatic rule would make a targeted sale redeemable as an order-level
 * voucher, which is a different (and unsupported) product.
 */
class SaveCouponAction
{
    public function __construct(
        private readonly PromotionManagerInterface $promotion,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CouponDTO
    {
        $this->assertCouponBackedDiscount((int) $data['discount_id']);

        $coupon = Coupon::query()->create([
            'discount_id' => (int) $data['discount_id'],
            'code' => Coupon::normalizeCode((string) $data['code']),
            'is_active' => $data['is_active'] ?? true,
            'usage_limit' => $data['usage_limit'] ?? null,
            'usage_limit_per_user' => $data['usage_limit_per_user'] ?? null,
        ]);

        return $this->promotion->findCoupon($coupon->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): CouponDTO
    {
        $coupon = Coupon::query()->findOrFail($id);

        if (array_key_exists('discount_id', $data)) {
            $this->assertCouponBackedDiscount((int) $data['discount_id']);
            $coupon->discount_id = (int) $data['discount_id'];
        }

        // The code itself is deliberately immutable once redemptions exist: it is
        // printed on the order's frozen snapshot and quoted by customers, so
        // repointing it at different terms would rewrite history.
        if (array_key_exists('code', $data)) {
            $normalized = Coupon::normalizeCode((string) $data['code']);

            if ($normalized !== $coupon->code && $coupon->redemptions()->exists()) {
                throw ValidationException::withMessages([
                    'code' => ['This coupon already has redemptions and its code can no longer be changed.'],
                ]);
            }

            $coupon->code = $normalized;
        }

        foreach (['is_active', 'usage_limit', 'usage_limit_per_user'] as $field) {
            if (array_key_exists($field, $data)) {
                $coupon->{$field} = $data[$field];
            }
        }

        $coupon->save();

        return $this->promotion->findCoupon($coupon->id);
    }

    /**
     * Soft-delete, so redemption history and the codes printed on historical orders
     * keep resolving. The unique index still holds the code, which is what prevents
     * it being reissued against new terms.
     */
    public function delete(int $id): void
    {
        Coupon::query()->findOrFail($id)->delete();
    }

    private function assertCouponBackedDiscount(int $discountId): void
    {
        $discount = Discount::query()->find($discountId);

        if ($discount === null
            || $discount->trigger_type !== DiscountTriggerType::COUPON
            || $discount->scope !== DiscountScope::ALL) {
            throw ValidationException::withMessages([
                'discount_id' => ['A coupon must be backed by a coupon-triggered, store-wide discount.'],
            ]);
        }
    }
}
