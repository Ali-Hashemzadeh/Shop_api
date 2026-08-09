<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A merchandising grouping. PRIVATE to Promotion.
 *
 * A campaign answers "why is this product shown here?", never "what is the best
 * price?". It carries no pricing rule of its own: it links automatic discounts,
 * and each of those keeps competing on equal terms with every other live rule.
 */
class Campaign extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'starts_at',
        'ends_at',
        'is_active',
        'show_on_landing',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'show_on_landing' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function discounts(): BelongsToMany
    {
        return $this->belongsToMany(Discount::class, 'campaign_discount')->withTimestamps();
    }

    /**
     * Publicly visible right now. Mirrors Discount::scopeCurrentlyActive so a
     * campaign and its rules answer the same date question.
     */
    public function scopeCurrentlyActive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }
}
