<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Models;

use App\Support\HasPublicCode;
use App\Support\PublicCodeEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Blog\Domain\Enums\BlogCommentStatus;

/**
 * A Blog discussion comment (`bdm-XXXXXX`). PRIVATE to the Blog module and
 * unrelated to the Review module. `user_id` is a loose Identity reference.
 * Soft-deleted so a removed parent keeps its replies' thread intact.
 */
class BlogComment extends Model
{
    use HasPublicCode;
    use SoftDeletes;

    protected $fillable = [
        'blog_post_id',
        'user_id',
        'parent_id',
        'body',
        'status',
        'edited_at',
        'approved_at',
        'approved_by_user_id',
    ];

    protected $casts = [
        'blog_post_id' => 'integer',
        'user_id' => 'integer',
        'parent_id' => 'integer',
        'status' => BlogCommentStatus::class,
        'edited_at' => 'datetime',
        'approved_at' => 'datetime',
        'approved_by_user_id' => 'integer',
    ];

    public static function publicCodeEntity(): PublicCodeEntity
    {
        return PublicCodeEntity::BlogComment;
    }

    /** @return BelongsTo<BlogPost, self> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'blog_post_id');
    }

    /** @return BelongsTo<self, self> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    /** @return HasMany<self> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<BlogCommentReport> */
    public function reports(): HasMany
    {
        return $this->hasMany(BlogCommentReport::class, 'comment_id');
    }
}
