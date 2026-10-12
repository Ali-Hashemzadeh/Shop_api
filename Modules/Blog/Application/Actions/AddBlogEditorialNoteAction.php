<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Modules\Blog\Domain\Models\BlogEditorialNote;
use Modules\Blog\Domain\Models\BlogPost;

/** Add a private editorial note to a post. Internal only. */
final class AddBlogEditorialNoteAction
{
    public function handle(BlogPost $post, int $userId, string $body): BlogEditorialNote
    {
        return BlogEditorialNote::query()->create([
            'blog_post_id' => $post->id,
            'user_id' => $userId,
            'body' => $body,
        ]);
    }
}
