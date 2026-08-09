<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A marketing code that activates a dormant coupon-backed discount. PRIVATE to Promotion.
 *
 * Several codes may point at one discount (SUMMER10 / SUMMER10VIP sharing a rule).
 * Soft-deleted rather than destroyed so historical redemptions keep resolving, and
 * the unique index on `code` is what stops a retired code being reissued to a new rule.
 */
class Coupon extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'discount_id',
        'code',
        'is_active',
        'usage_limit',
        'usage_limit_per_user',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'usage_limit' => 'integer',
            'usage_limit_per_user' => 'integer',
        ];
    }

    /**
     * Canonical storage form for a customer-typed code.
     *
     * Codes are quoted from posters, SMS, and word of mouth, so " summer10 " and
     * "SUMMER10" must resolve to the same coupon. Normalizing on the way in *and*
     * on every lookup keeps the unique index meaningful.
     */
    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }
}
