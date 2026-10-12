<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsBlogDailyStat extends Model
{
    protected $table = 'analytics_blog_daily_stats';

    protected $fillable = [
        'date',
        'blog_post_id',
        'views',
        'likes',
        'bookmarks',
        'comments',
        'product_clicks',
    ];

    protected $casts = [
        'blog_post_id' => 'integer',
        'views' => 'integer',
        'likes' => 'integer',
        'bookmarks' => 'integer',
        'comments' => 'integer',
        'product_clicks' => 'integer',
    ];
}
