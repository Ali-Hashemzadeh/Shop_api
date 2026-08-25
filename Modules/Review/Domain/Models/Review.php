<?php

namespace Modules\Review\Domain\Models;

use App\Support\HasPublicCode;
use App\Support\PublicCodeEntity;
use Illuminate\Database\Eloquent\Model;
use Modules\Review\Domain\Enums\ReviewStatus;
use Modules\Review\Domain\Enums\ReviewSubjectType;

class Review extends Model
{
    use HasPublicCode;

    protected $fillable = [
        'subject_type',
        'subject_id',
        'user_id',
        'rating',
        'body',
        'gallery_media_ids',
        'verified_purchase',
        'status',
        'seller_reply',
        'seller_reply_at',
    ];

    protected $casts = [
        // Enum-backed whitelist — never free text.
        'subject_type' => ReviewSubjectType::class,
        'status' => ReviewStatus::class,
        'rating' => 'integer',
        'gallery_media_ids' => 'array',
        'verified_purchase' => 'boolean',
        'seller_reply_at' => 'datetime',
    ];

    /**
     * The customer-facing handle (`bdr-XXXXXX`) for display and support.
     * The integer id stays the structural key for the unique
     * (user_id, subject_type, subject_id) constraint and every query.
     */
    public static function publicCodeEntity(): PublicCodeEntity
    {
        return PublicCodeEntity::Review;
    }

    public static function publicCodeColumn(): string
    {
        return 'uuid';
    }
}
