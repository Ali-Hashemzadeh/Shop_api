<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Promotion\Domain\Enums\RedemptionStatus;

/**
 * One order's claim on a coupon. PRIVATE to Promotion.
 *
 * `order_id` and `user_id` are loose primitives supplied by the Order module —
 * Promotion never calls Order back, which is what keeps the dependency arrow
 * pointing Order → Promotion and not both ways.
 */
class CouponRedemption extends Model
{
    protected $fillable = [
        'coupon_id',
        'order_id',
        'user_id',
        'status',
        'discount_amount',
    ];

    protected function casts(): array
    {
        return [
            'status' => RedemptionStatus::class,
            'order_id' => 'integer',
            'user_id' => 'integer',
            'discount_amount' => 'integer',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
