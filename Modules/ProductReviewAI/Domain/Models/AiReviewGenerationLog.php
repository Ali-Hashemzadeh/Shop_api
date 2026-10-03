<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ProductReviewAI\Domain\Enums\GenerationLogType;

/**
 * Append-only debugging ledger for a generation run: every external/AI
 * request+response and every moderation decision, success or failure.
 */
class AiReviewGenerationLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'generation_id',
        'type',
        'request',
        'response',
        'status',
        'error',
    ];

    protected $casts = [
        'generation_id' => 'integer',
        'type' => GenerationLogType::class,
        'request' => 'array',
        'response' => 'array',
    ];

    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiReviewGeneration::class, 'generation_id');
    }
}
