<?php

declare(strict_types=1);

namespace Modules\Wishlist\Infrastructure\Persistence\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Wishlist\Domain\Contracts\WishlistManagerInterface;
use Modules\Wishlist\Domain\Models\AvailabilitySubscription;
use Modules\Wishlist\Domain\Models\ProductLike;

class EloquentWishlistManager implements WishlistManagerInterface
{
    // ── Likes ───────────────────────────────────────────────────────────────

    public function like(int $userId, int $productId): void
    {
        // firstOrCreate + the unique index make a repeated like a no-op instead
        // of a duplicate row or a constraint error.
        ProductLike::query()->firstOrCreate([
            'user_id' => $userId,
            'product_id' => $productId,
        ]);
    }

    public function unlike(int $userId, int $productId): void
    {
        ProductLike::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->delete();
    }

    public function isLiked(int $userId, int $productId): bool
    {
        return ProductLike::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->exists();
    }

    public function likedProductIds(int $userId, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds)));

        if ($productIds === []) {
            return [];
        }

        return ProductLike::query()
            ->where('user_id', $userId)
            ->whereIn('product_id', $productIds)
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function paginateLikedProductIds(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return ProductLike::query()
            ->where('user_id', $userId)
            ->latest('id')
            ->paginate($perPage)
            ->through(static fn (ProductLike $like): int => (int) $like->product_id);
    }

    // ── Availability subscriptions ────────────────────────────────────────────

    public function requestAvailabilityNotification(int $userId, string $sku): void
    {
        // One row per (user, sku). A brand-new request is created active; a
        // previously consumed one is reactivated (notified_at → null) so the
        // customer can ask to be alerted again after a later sell-out. Pressing
        // the button repeatedly while already active changes nothing.
        AvailabilitySubscription::query()->updateOrCreate(
            ['user_id' => $userId, 'sku' => $sku],
            ['notified_at' => null],
        );
    }

    public function cancelAvailabilityNotification(int $userId, string $sku): void
    {
        AvailabilitySubscription::query()
            ->where('user_id', $userId)
            ->where('sku', $sku)
            ->delete();
    }

    public function hasActiveAvailabilitySubscription(int $userId, string $sku): bool
    {
        return AvailabilitySubscription::query()
            ->where('user_id', $userId)
            ->where('sku', $sku)
            ->whereNull('notified_at')
            ->exists();
    }

    public function activeSubscribedSkus(int $userId, array $skus): array
    {
        $skus = array_values(array_unique(array_filter($skus)));

        if ($skus === []) {
            return [];
        }

        return AvailabilitySubscription::query()
            ->where('user_id', $userId)
            ->whereIn('sku', $skus)
            ->whereNull('notified_at')
            ->pluck('sku')
            ->all();
    }

    public function claimPendingSubscribersForSku(string $sku): array
    {
        return DB::transaction(function () use ($sku): array {
            // Lock the active rows, read the audience, then stamp them consumed
            // inside the same transaction. A concurrent worker (or a retried
            // event) blocks on the lock and afterwards finds nothing still null,
            // so every subscription yields exactly one notification.
            $subscriptions = AvailabilitySubscription::query()
                ->where('sku', $sku)
                ->whereNull('notified_at')
                ->lockForUpdate()
                ->get();

            if ($subscriptions->isEmpty()) {
                return [];
            }

            AvailabilitySubscription::query()
                ->whereIn('id', $subscriptions->pluck('id'))
                ->update(['notified_at' => now()]);

            return $subscriptions
                ->pluck('user_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        });
    }
}
