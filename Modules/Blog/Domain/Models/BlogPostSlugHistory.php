<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A slug a published post used to answer to. Each retired slug belongs to
 * exactly one post (unique index) and keeps resolving to that post's current
 * canonical slug.
 */
class BlogPostSlugHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'blog_post_slug_history';

    protected $fillable = [
        'blog_post_id',
        'slug',
    ];

    protected $casts = [
        'blog_post_id' => 'integer',
    ];
}
