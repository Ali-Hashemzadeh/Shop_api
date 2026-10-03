<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only edit history for a draft. One row per snapshot of the draft
 * content (name/rating/title/body) as it stood at a change.
 */
class AiGeneratedReviewVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'generated_review_id',
        'content',
        'changed_by',
    ];

    protected $casts = [
        'generated_review_id' => 'integer',
        'content' => 'array',
        'changed_by' => 'integer',
    ];

    public function generatedReview(): BelongsTo
    {
        return $this->belongsTo(AiGeneratedReview::class, 'generated_review_id');
    }
}
