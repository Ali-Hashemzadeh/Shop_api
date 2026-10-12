<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One entry of the editorial audit trail (who did what, when). Append-only and
 * separate from content revisions.
 */
class BlogPostActivity extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'blog_post_activity';

    protected $fillable = [
        'blog_post_id',
        'actor_user_id',
        'action',
        'metadata',
    ];

    protected $casts = [
        'blog_post_id' => 'integer',
        'actor_user_id' => 'integer',
        'metadata' => 'array',
    ];
}
