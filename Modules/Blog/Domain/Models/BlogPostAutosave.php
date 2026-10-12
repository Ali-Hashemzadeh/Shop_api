<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An editor's latest autosave for a post: one replaceable scratch row per
 * (post, editor). Never an official revision; a manual save clears it.
 */
class BlogPostAutosave extends Model
{
    protected $fillable = [
        'blog_post_id',
        'user_id',
        'title',
        'excerpt',
        'content',
        'base_version',
    ];

    protected $casts = [
        'blog_post_id' => 'integer',
        'user_id' => 'integer',
        'base_version' => 'integer',
    ];
}
