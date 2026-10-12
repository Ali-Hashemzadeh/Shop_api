<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Blog\Domain\Models\BlogTag;

/**
 * Delete a tag with explicit detach semantics: tags are non-structural labels,
 * so the tag is removed from every post (pivot rows cascade) and its follower
 * subscriptions are dropped, in one transaction. Posts themselves are untouched.
 */
final class DeleteBlogTagAction
{
    public function handle(BlogTag $tag): void
    {
        DB::transaction(function () use ($tag): void {
            DB::table('blog_post_tag')->where('blog_tag_id', $tag->id)->delete();
            DB::table('blog_subscriptions')->where('subject_type', 'tag')->where('subject_id', $tag->id)->delete();
            $tag->delete();
        });
    }
}
