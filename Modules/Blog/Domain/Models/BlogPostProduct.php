<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A shoppable-article link to a Catalog product. `product_id` is a loose
 * primitive (no FK, no Catalog model); live product data — including price — is
 * always hydrated through CatalogManagerInterface, never duplicated here.
 *
 * Composite key (blog_post_id, product_id): rows are only inserted or deleted in
 * bulk by the post-sync Action, never updated through the model.
 */
class BlogPostProduct extends Model
{
    protected $table = 'blog_post_products';

    protected $primaryKey = 'blog_post_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'blog_post_id',
        'product_id',
        'relation_type',
        'sort_order',
    ];

    protected $casts = [
        'blog_post_id' => 'integer',
        'product_id' => 'integer',
        'sort_order' => 'integer',
    ];
}
