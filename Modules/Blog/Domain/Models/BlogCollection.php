<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A curated editorial grouping (e.g. "Editor's Picks", "Nowruz Guides"),
 * distinct from categories and tags. Visible while active and inside its
 * optional date window.
 */
class BlogCollection extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function scopeCurrentlyVisible(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    /** @return BelongsToMany<BlogPost> */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(BlogPost::class, 'blog_collection_posts')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }
}
