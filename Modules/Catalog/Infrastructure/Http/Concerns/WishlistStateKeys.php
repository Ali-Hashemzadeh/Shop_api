<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Http\Concerns;

/**
 * Request-attribute keys under which the controller stashes the current
 * customer's pre-resolved like / availability state for the page, so the
 * resources can read it without an extra query per row.
 *
 * A plain class (not the trait) holds them because PHP does not allow reading a
 * constant through the trait name, and both the trait and the resources need to
 * agree on the exact keys.
 */
final class WishlistStateKeys
{
    public const LIKED_ATTR = 'wishlist.liked_product_ids';

    public const SUBSCRIBED_ATTR = 'wishlist.subscribed_skus';
}
