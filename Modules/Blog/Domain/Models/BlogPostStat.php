<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Blog-owned engagement counters for one post (the read model behind the
 * metric sorts and `likes_count`/`comments_count`). Maintained only by Blog's
 * own write paths; never computed by another module, never faked.
 */
class BlogPostStat extends Model
{
    public const CREATED_AT = null;

    protected $table = 'blog_post_stats';

    protected $primaryKey = 'blog_post_id';

    public $incrementing = false;

    protected $fillable = [
        'blog_post_id',
        'views_count',
        'likes_count',
        'bookmarks_count',
        'comments_count',
        'product_clicks_count',
    ];

    protected $casts = [
        'views_count' => 'integer',
        'likes_count' => 'integer',
        'bookmarks_count' => 'integer',
        'comments_count' => 'integer',
        'product_clicks_count' => 'integer',
    ];
}
