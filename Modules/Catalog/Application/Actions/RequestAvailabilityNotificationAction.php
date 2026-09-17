<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Actions;

use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Wishlist\Domain\Contracts\WishlistManagerInterface;

/**
 * Subscribe the authenticated customer to a restock alert for one variant SKU.
 *
 * The subscription is always keyed to the exact SKU, so a later restock of a
 * sibling variant of the same product never notifies this user. Catalog checks
 * the SKU belongs to a *purchasable* (published) product and 404s otherwise
 * before handing the primitive SKU to Wishlist. Idempotent: pressing the button
 * again while already subscribed changes nothing.
 */
class RequestAvailabilityNotificationAction
{
    public function __construct(
        private readonly WishlistManagerInterface $wishlist,
    ) {}

    public function handle(int $userId, string $sku): void
    {
        // Must exist AND belong to a published product. A draft/unknown SKU is a
        // 404 via firstOrFail, and we never leak draft existence separately.
        ProductVariant::query()
            ->where('sku', $sku)
            ->whereHas('product', fn ($q) => $q->where('status', 'published'))
            ->firstOrFail();

        $this->wishlist->requestAvailabilityNotification($userId, $sku);
    }
}
