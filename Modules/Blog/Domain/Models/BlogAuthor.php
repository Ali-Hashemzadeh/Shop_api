<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A Blog-owned public author profile. PRIVATE to the Blog module.
 *
 * Public responses render this profile, never the Identity user behind it.
 * `user_id` is a loose Identity reference (no FK), validated only through
 * IdentityManagerInterface.
 */
class BlogAuthor extends Model
{
    protected $fillable = [
        'user_id',
        'display_name',
        'slug',
        'bio',
        'media_id',
        'job_title',
        'is_active',
        'seo_title',
        'seo_description',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'media_id' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @return BelongsToMany<BlogPost> */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(BlogPost::class, 'blog_post_author')->withPivot(['role', 'sort_order']);
    }
}
