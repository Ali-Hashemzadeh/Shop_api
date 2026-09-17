<?php

declare(strict_types=1);

namespace Modules\Wishlist\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A customer's one-shot "notify me when available" request for a single
 * variant SKU.
 *
 * `user_id` is a loose reference (no FK). `sku` is the exact Catalog/Inventory
 * SKU string — the subscription is tied to the specific variant, never merely
 * the product, so a restock of a *different* variant of the same product never
 * notifies this user.
 *
 * One-shot: `notified_at` is null while the request is active and stamped once
 * the restock notification has been sent. The unique index on (user_id, sku)
 * means re-subscribing after a notification simply reactivates the same row
 * (notified_at → null) rather than creating a second one.
 */
class AvailabilitySubscription extends Model
{
    protected $table = 'availability_subscriptions';

    protected $fillable = [
        'user_id',
        'sku',
        'notified_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'notified_at' => 'datetime',
    ];
}
