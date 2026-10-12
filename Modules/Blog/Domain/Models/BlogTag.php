<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A flat Blog tag. PRIVATE to the Blog module. Tags are managed explicitly
 * through the admin API and are never created implicitly from post input.
 */
class BlogTag extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** @return BelongsToMany<BlogPost> */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(BlogPost::class, 'blog_post_tag');
    }
}
