<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Actions;

use Modules\Catalog\Domain\Models\Product;
use Modules\Wishlist\Domain\Contracts\WishlistManagerInterface;

/**
 * Like a product for the authenticated customer.
 *
 * Catalog resolves the public product code → internal id (and 404s an unknown
 * one) before handing the primitive id to Wishlist, which owns the like table.
 * Idempotent: liking an already-liked product changes nothing.
 */
class LikeProductAction
{
    public function __construct(
        private readonly WishlistManagerInterface $wishlist,
    ) {}

    public function handle(int $userId, string $productUuid): void
    {
        $product = Product::query()->where('uuid', $productUuid)->firstOrFail();

        $this->wishlist->like($userId, (int) $product->id);
    }
}
