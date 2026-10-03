<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Connects an internal product (by integer id — a loose cross-module reference,
 * no FK) to an external marketplace product.
 */
class ExternalProductMapping extends Model
{
    protected $fillable = [
        'product_id',
        'source_id',
        'external_id',
        'external_url',
        'metadata',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'source_id' => 'integer',
        'metadata' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(ReviewSource::class, 'source_id');
    }
}
