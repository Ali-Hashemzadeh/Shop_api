<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An immutable snapshot of a complete article version. Append-only: rows are
 * never updated or deleted by normal editing, and restoring one creates a NEW
 * revision rather than rewinding history.
 */
class BlogPostRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'blog_post_id',
        'revision_number',
        'title',
        'slug',
        'excerpt',
        'content',
        'featured_media_id',
        'status_snapshot',
        'seo_snapshot',
        'taxonomy_snapshot',
        'author_snapshot',
        'created_by_user_id',
    ];

    protected $casts = [
        'blog_post_id' => 'integer',
        'revision_number' => 'integer',
        'featured_media_id' => 'integer',
        'seo_snapshot' => 'array',
        'taxonomy_snapshot' => 'array',
        'author_snapshot' => 'array',
        'created_by_user_id' => 'integer',
    ];
}
