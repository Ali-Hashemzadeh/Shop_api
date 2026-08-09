<?php

declare(strict_types=1);

namespace Modules\Order\Application\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Order\Domain\Enums\OrderStatus;
use Modules\Order\Domain\Models\Order;
use Modules\Promotion\Domain\Contracts\PromotionManagerInterface;
use Modules\Promotion\Domain\Exceptions\CouponRejectedException;

/**
 * Advisory coupon preview for an existing pending order.
 *
 * Strictly read-only: it reserves nothing and writes nothing, so a customer may
 * try ten codes without consuming a single use. That also means the answer is only
 * a preview — the coupon may expire, be disabled, or be exhausted by someone else
 * before payment, so InitializePayment revalidates from scratch and is the only
 * authority on what is actually charged.
 */
class CheckOrderCouponAction
{
    public function __construct(
        private readonly PromotionManagerInterface $promotion,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException 422 for any unusable code
     */
    public function handle(int $orderId, int $userId, string $code): array
    {
        $order = Order::with('items')->find($orderId);

        if ($order === null) {
            abort(404, 'Order not found.');
        }

        if ($order->user_id !== $userId) {
            abort(403, 'This order does not belong to you.');
        }

        if ($order->status !== OrderStatus::PENDING->value) {
            abort(422, 'Only pending orders can have a coupon applied.');
        }

        if ($order->payment_pricing_finalized_at !== null) {
            // Pricing is committed; previewing an alternative would imply it could
            // still be swapped in, which it cannot.
            throw ValidationException::withMessages([
                'code' => ['The coupon for this order can no longer be changed.'],
            ]);
        }

        // The frozen post-automatic merchandise figure — shipping and tax are
        // deliberately excluded, so a coupon never discounts delivery.
        $merchandiseSubtotal = $order->merchandiseSubtotal();

        try {
            $quote = $this->promotion->quoteCoupon($code, $userId, $merchandiseSubtotal);
        } catch (CouponRejectedException $e) {
            throw ValidationException::withMessages(['code' => [$e->getMessage()]]);
        }

        $totalBeforeCoupon = $merchandiseSubtotal + $order->shipping_cost + $order->tax_amount;
        $totalAfterCoupon = $totalBeforeCoupon - $quote->discountAmount;

        if ($totalAfterCoupon <= 0) {
            throw ValidationException::withMessages([
                'code' => ['This coupon cannot be applied to this order.'],
            ]);
        }

        return [
            'code' => $quote->code,
            'discount_type' => $quote->discountType->value,
            'percentage_bps' => $quote->percentageBps,
            'fixed_amount' => $quote->fixedAmount,
            'discount_amount' => $quote->discountAmount,
            'merchandise_subtotal' => $merchandiseSubtotal,
            'shipping_cost' => $order->shipping_cost,
            'tax_amount' => $order->tax_amount,
            'total_before_coupon' => $totalBeforeCoupon,
            'total_after_coupon' => $totalAfterCoupon,
        ];
    }
}
