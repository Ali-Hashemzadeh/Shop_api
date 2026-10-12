<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One article placed in a keyed editorial slot (e.g. `blog.home.hero`) for an
 * optional date window. Keys are data, never schema.
 */
class BlogPlacement extends Model
{
    protected $fillable = [
        'placement_key',
        'blog_post_id',
        'starts_at',
        'ends_at',
        'sort_order',
    ];

    protected $casts = [
        'blog_post_id' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function scopeActiveNow(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    /** @return BelongsTo<BlogPost, self> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'blog_post_id');
    }
}
