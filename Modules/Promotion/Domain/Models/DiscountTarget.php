<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Promotion\Domain\Enums\DiscountTargetType;

/**
 * One Catalog entity a targeted discount points at. PRIVATE to Promotion.
 *
 * `target_id` is a loose primitive — there is no relationship method to a Catalog
 * model and no FK, because Promotion must not depend on Catalog. Resolving what a
 * target *means* is Catalog's job, using the definitions Promotion publishes.
 */
class DiscountTarget extends Model
{
    protected $fillable = [
        'discount_id',
        'target_type',
        'target_id',
    ];

    protected function casts(): array
    {
        return [
            'target_type' => DiscountTargetType::class,
            'target_id' => 'integer',
        ];
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }
}
