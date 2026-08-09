<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\DTOs;

/**
 * Everything Promotion needs to price one variant, supplied by the caller.
 *
 * This DTO is what breaks the would-be cycle: Catalog depends on Promotion for
 * live pricing, so Promotion must never query Catalog back. Instead Catalog —
 * which already owns the product, its category ancestry, and its brand — hands
 * the facts over, and Promotion consults only its own tables.
 */
class AutomaticDiscountContextDTO
{
    /**
     * @param  array<int, int>  $categoryIds  The variant's own category *and every
     *                                        ancestor of it*, already resolved by the
     *                                        caller. Promotion never walks the Catalog
     *                                        tree itself, so a discount on "Electronics"
     *                                        reaches an "Android" product only because
     *                                        Catalog listed both ids here.
     */
    public function __construct(
        public readonly int $variantId,
        public readonly int $productId,
        public readonly array $categoryIds,
        public readonly ?int $brandId,
        public readonly int $basePrice,
    ) {}
}
