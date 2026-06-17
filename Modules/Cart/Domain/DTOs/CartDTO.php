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
        public readonly int $totalPrice,
    ) {}

    /** @param CartItemDTO[] $items */
    public static function fromModel(Cart $cart, array $items = []): self
    {
        $totalQuantity = array_sum(array_map(static fn (CartItemDTO $i) => $i->quantity, $items));
        $totalPrice = array_sum(array_map(static fn (CartItemDTO $i) => $i->lineTotal, $items));

        return new self(
            id: $cart->id,
            userId: $cart->user_id,
            sessionId: $cart->session_id,
            items: $items,
            itemCount: count($items),
            totalQuantity: $totalQuantity,
            totalPrice: $totalPrice,
        );
    }
}
