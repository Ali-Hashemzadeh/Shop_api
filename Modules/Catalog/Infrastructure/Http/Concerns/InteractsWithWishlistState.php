<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Http\Concerns;

use Illuminate\Http\Request;
use Modules\Catalog\Domain\DTOs\ProductDTO;
use Modules\Catalog\Domain\DTOs\ProductVariantDTO;
use Modules\Wishlist\Domain\Contracts\WishlistManagerInterface;

/**
 * Resolves the current customer's like / availability state for a page of
 * products (or variants) in ONE batch query each, then stashes it on the
 * request so ProductResource / ProductVariantResource can render `is_liked` and
 * `availability_notification_requested` without an extra query per row.
 *
 * Auth is optional: these annotate public storefront reads too, so the user is
 * resolved via the Sanctum guard (which returns null for a guest rather than
 * 401, exactly like the cart's identify step). A guest — or any endpoint that
 * does not call these — leaves the attributes unset, and both flags read false.
 */
trait InteractsWithWishlistState
{
    /**
     * @param  iterable<ProductDTO>  $products
     */
    protected function annotateProductWishlistState(Request $request, iterable $products): void
    {
        $user = auth('sanctum')->user();

        if ($user === null) {
            return;
        }

        $productIds = [];
        $skus = [];

        foreach ($products as $product) {
            $productIds[] = $product->id;

            foreach ($product->variants as $variant) {
                $skus[] = $variant->sku;
            }
        }

        $userId = (int) $user->getAuthIdentifier();
        $wishlist = app(WishlistManagerInterface::class);

        if ($productIds !== []) {
            $request->attributes->set(
                WishlistStateKeys::LIKED_ATTR,
                array_fill_keys($wishlist->likedProductIds($userId, $productIds), true),
            );
        }

        if ($skus !== []) {
            $request->attributes->set(
                WishlistStateKeys::SUBSCRIBED_ATTR,
                array_fill_keys($wishlist->activeSubscribedSkus($userId, $skus), true),
            );
        }
    }

    /**
     * @param  iterable<ProductVariantDTO>  $variants
     */
    protected function annotateVariantWishlistState(Request $request, iterable $variants): void
    {
        $user = auth('sanctum')->user();

        if ($user === null) {
            return;
        }

        $skus = [];

        foreach ($variants as $variant) {
            $skus[] = $variant->sku;
        }

        if ($skus === []) {
            return;
        }

        $request->attributes->set(
            WishlistStateKeys::SUBSCRIBED_ATTR,
            array_fill_keys(
                app(WishlistManagerInterface::class)->activeSubscribedSkus((int) $user->getAuthIdentifier(), $skus),
                true,
            ),
        );
    }
}
