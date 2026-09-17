<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Actions;

use Modules\Wishlist\Domain\Contracts\WishlistManagerInterface;

/**
 * Cancel the authenticated customer's restock alert for one variant SKU.
 *
 * Scoped to the caller's own subscription by Wishlist, and idempotent — removing
 * a subscription that does not exist is a no-op, so the endpoint is safe to call
 * regardless of current state. No SKU existence check is needed: there is simply
 * nothing to remove for an unknown SKU.
 */
class CancelAvailabilityNotificationAction
{
    public function __construct(
        private readonly WishlistManagerInterface $wishlist,
    ) {}

    public function handle(int $userId, string $sku): void
    {
        $this->wishlist->cancelAvailabilityNotification($userId, $sku);
    }
}
