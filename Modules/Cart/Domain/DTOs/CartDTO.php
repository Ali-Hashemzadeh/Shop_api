<?php

declare(strict_types=1);

namespace Modules\Cart\Domain\DTOs;

use Modules\Cart\Domain\Models\Cart;

class CartDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $userId,
        public readonly ?string $sessionId,
        public readonly array $items,
        public readonly int $itemCount,
        public readonly int $totalQuantity,
        /** Payable merchandise total: the sum of effective-price line totals. */
        public readonly int $totalPrice,
        /** The same basket at regular prices, for a strike-through figure. */
        public readonly int $regularTotalPrice = 0,
        /** regularTotalPrice − totalPrice; what automatic discounts saved. */
        public readonly int $automaticDiscountTotal = 0,
    ) {}

    /** @param CartItemDTO[] $items */
    public static function fromModel(Cart $cart, array $items = []): self
    {
        $totalQuantity = array_sum(array_map(static fn (CartItemDTO $i) => $i->quantity, $items));
        // Already net of automatic discounts — CartItemDTO::lineTotal is priced at
        // effectivePrice, so this is what checkout will charge for merchandise.
        $totalPrice = array_sum(array_map(static fn (CartItemDTO $i) => $i->lineTotal, $items));
        $regularTotalPrice = array_sum(array_map(static fn (CartItemDTO $i) => $i->regularLineTotal, $items));

        return new self(
            id: $cart->id,
            userId: $cart->user_id,
            sessionId: $cart->session_id,
            items: $items,
            itemCount: count($items),
            totalQuantity: $totalQuantity,
            totalPrice: $totalPrice,
            regularTotalPrice: $regularTotalPrice,
            automaticDiscountTotal: max(0, $regularTotalPrice - $totalPrice),
        );
    }
}
