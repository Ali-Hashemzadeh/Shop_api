<?php

declare(strict_types=1);

namespace Modules\Wishlist\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The Wishlist module's public surface — the only way other modules touch
 * product likes and availability subscriptions.
 *
 * Wishlist is a leaf: every argument and return value here is a primitive or a
 * primitive collection. Catalog resolves public codes → ids and validates that
 * a product/variant exists before calling in; Wishlist stores and reads its own
 * two tables and nothing else. It imports no other module's models, contracts,
 * or events.
 */
interface WishlistManagerInterface
{
    // ── Likes ───────────────────────────────────────────────────────────────

    /** Like a product for a user. Idempotent: a second call is a no-op. */
    public function like(int $userId, int $productId): void;

    /** Remove a user's like. Idempotent: unliking something not liked is a no-op. */
    public function unlike(int $userId, int $productId): void;

    public function isLiked(int $userId, int $productId): bool;

    /**
     * Of the given product ids, which ones this user has liked. One query, used
     * to annotate a whole product page without an N+1.
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    public function likedProductIds(int $userId, array $productIds): array;

    /**
     * A user's liked product ids, newest-like first, paginated. The paginator's
     * totals come from the likes table; Catalog hydrates the page's ids into
     * product representations.
     *
     * @return LengthAwarePaginator<int, int>
     */
    public function paginateLikedProductIds(int $userId, int $perPage = 15): LengthAwarePaginator;

    // ── Availability subscriptions ────────────────────────────────────────────

    /**
     * Record (or reactivate) a user's one-shot availability request for a SKU.
     * Idempotent while active, and resets notified_at → null if a previous
     * request for the same SKU had already been consumed.
     */
    public function requestAvailabilityNotification(int $userId, string $sku): void;

    /** Remove a user's availability request for a SKU. Idempotent. */
    public function cancelAvailabilityNotification(int $userId, string $sku): void;

    public function hasActiveAvailabilitySubscription(int $userId, string $sku): bool;

    /**
     * Of the given SKUs, which ones this user has an *active* (not yet consumed)
     * subscription for. One query, to annotate a page of variants without N+1.
     *
     * @param  list<string>  $skus
     * @return list<string>
     */
    public function activeSubscribedSkus(int $userId, array $skus): array;

    /**
     * Atomically consume every active subscription for a restocked SKU and
     * return the ids of the users who must now be notified.
     *
     * Each subscription is claimed exactly once even under concurrent workers or
     * a duplicated restock event: the claim stamps notified_at under a row lock,
     * so a second caller sees nothing left to claim. This is what makes the
     * one-shot guarantee and the no-duplicate-notification guarantee hold without
     * a separate processed-events table.
     *
     * @return list<int> user ids claimed by *this* call
     */
    public function claimPendingSubscribersForSku(string $sku): array;
}
