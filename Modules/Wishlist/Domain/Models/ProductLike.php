<?php

declare(strict_types=1);

namespace Modules\Wishlist\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One customer's "favourite" mark on one product.
 *
 * `user_id` and `product_id` are loose references (no FK), exactly like
 * `reviews.user_id`/`subject_id` and `notifications.user_id` elsewhere — this
 * module never imports Identity's or Catalog's models and never joins their
 * tables. The unique index on (user_id, product_id) guarantees one like per
 * user per product.
 */
class ProductLike extends Model
{
    protected $table = 'product_likes';

    protected $fillable = [
        'user_id',
        'product_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'product_id' => 'integer',
    ];
}
