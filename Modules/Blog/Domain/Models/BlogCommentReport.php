<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Blog\Domain\Enums\BlogCommentReportReason;
use Modules\Blog\Domain\Enums\BlogCommentReportStatus;

/**
 * A reader's report against a comment. One open report per reader per comment.
 */
class BlogCommentReport extends Model
{
    protected $fillable = [
        'comment_id',
        'reported_by_user_id',
        'reason',
        'details',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected $casts = [
        'comment_id' => 'integer',
        'reported_by_user_id' => 'integer',
        'reason' => BlogCommentReportReason::class,
        'status' => BlogCommentReportStatus::class,
        'reviewed_by_user_id' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    /** @return BelongsTo<BlogComment, self> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(BlogComment::class, 'comment_id')->withTrashed();
    }
}
