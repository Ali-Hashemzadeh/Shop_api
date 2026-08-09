<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Promotion\Domain\Enums\DiscountScope;
use Modules\Promotion\Domain\Enums\DiscountTriggerType;
use Modules\Promotion\Domain\Enums\DiscountType;

/**
 * A pricing rule. PRIVATE to the Promotion module — no other module may import it.
 *
 * Two mutually exclusive shapes, enforced by the admin Form Requests and by
 * SaveDiscountAction:
 *   - automatic + targeted, with >= 1 discount_targets row
 *   - coupon    + all,      with exactly 0 discount_targets rows
 */
class Discount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'trigger_type',
        'scope',
        'discount_type',
        'percentage_bps',
        'fixed_amount',
        'max_discount_amount',
        'min_subtotal',
        'starts_at',
        'ends_at',
        'is_active',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'trigger_type' => DiscountTriggerType::class,
            'scope' => DiscountScope::class,
            'discount_type' => DiscountType::class,
            // Cents Rule: every money field is a raw integer; percentage_bps is an
            // integer count of basis points. Nothing here is ever a float.
            'percentage_bps' => 'integer',
            'fixed_amount' => 'integer',
            'max_discount_amount' => 'integer',
            'min_subtotal' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(DiscountTarget::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class, 'campaign_discount')->withTimestamps();
    }

    /**
     * The single definition of "usable right now", shared by automatic evaluation,
     * coupon validation, campaign resolution, and the has_discount filter.
     *
     * Deliberately one scope rather than four hand-rolled date comparisons: a rule
     * that looked live on the product page but had expired by checkout would be a
     * pricing bug, so every caller must ask the same question.
     */
    public function scopeCurrentlyActive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    /** True when this rule is within its active window right now. */
    public function isCurrentlyActive(): bool
    {
        if (! $this->is_active || $this->trashed()) {
            return false;
        }

        $now = now();

        return ! ($this->starts_at !== null && $this->starts_at->greaterThan($now))
            && ! ($this->ends_at !== null && $this->ends_at->lessThan($now));
    }
}
