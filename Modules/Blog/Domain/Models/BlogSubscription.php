<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Blog\Domain\Enums\BlogSubscriptionSubject;

/**
 * A reader following a Blog category, tag, or author. Subjects are Blog-owned
 * and referenced by enum-whitelisted type + id (no morph map).
 */
class BlogSubscription extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'subject_type' => BlogSubscriptionSubject::class,
        'subject_id' => 'integer',
    ];
}
