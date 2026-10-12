<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A private editorial collaboration note on a post. Internal only: no public
 * query ever selects from this table.
 */
class BlogEditorialNote extends Model
{
    protected $fillable = [
        'blog_post_id',
        'user_id',
        'body',
        'resolved_at',
    ];

    protected $casts = [
        'blog_post_id' => 'integer',
        'user_id' => 'integer',
        'resolved_at' => 'datetime',
    ];
}
