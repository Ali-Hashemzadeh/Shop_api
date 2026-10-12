<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Media asset used by an article, with the article-specific alt text and
 * caption. `media_id` is a loose reference resolved through MediaManagerInterface.
 */
class BlogPostMedia extends Model
{
    protected $table = 'blog_post_media';

    protected $fillable = [
        'blog_post_id',
        'media_id',
        'role',
        'alt_text',
        'caption',
        'sort_order',
    ];

    protected $casts = [
        'blog_post_id' => 'integer',
        'media_id' => 'integer',
        'sort_order' => 'integer',
    ];
}
