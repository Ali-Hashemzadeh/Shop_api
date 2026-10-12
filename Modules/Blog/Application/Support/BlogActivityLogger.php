<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Support;

use Modules\Blog\Domain\Enums\BlogActivityAction;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostActivity;

/**
 * Appends to the editorial audit trail. Callers invoke it inside their own
 * write transaction so an action and its audit entry commit (or roll back)
 * together.
 */
final class BlogActivityLogger
{
    /** @param  array<string, mixed>|null  $metadata */
    public function log(BlogPost $post, BlogActivityAction $action, ?int $actorUserId, ?array $metadata = null): void
    {
        BlogPostActivity::query()->create([
            'blog_post_id' => $post->id,
            'actor_user_id' => $actorUserId,
            'action' => $action->value,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
