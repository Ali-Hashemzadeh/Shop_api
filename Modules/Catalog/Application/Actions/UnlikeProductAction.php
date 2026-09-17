<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Actions;

use Modules\Catalog\Domain\Models\Product;
use Modules\Wishlist\Domain\Contracts\WishlistManagerInterface;

/**
 * Remove the authenticated customer's like from a product.
 *
 * Scoped to the caller's own like by Wishlist (it deletes only the
 * (user_id, product_id) row), so one customer can never remove another's.
 * Idempotent: unliking something not liked is a no-op.
 */
class UnlikeProductAction
{
    public function __construct(
        private readonly WishlistManagerInterface $wishlist,
    ) {}

    public function handle(int $userId, string $productUuid): void
    {
        $product = Product::query()->where('uuid', $productUuid)->firstOrFail();

        $this->wishlist->unlike($userId, (int) $product->id);
    }
}
