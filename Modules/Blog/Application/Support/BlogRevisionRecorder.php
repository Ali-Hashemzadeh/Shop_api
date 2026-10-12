<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Support;

use Modules\Blog\Domain\Models\BlogAuthor;
use Modules\Blog\Domain\Models\BlogCategory;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostRevision;

/**
 * Appends an immutable revision capturing everything needed to restore a
 * complete article version: content, slug, featured media, SEO, taxonomy, and
 * authorship. Revision numbers are per post and strictly increasing; the
 * `(blog_post_id, revision_number)` unique index backstops concurrent saves.
 */
final class BlogRevisionRecorder
{
    public function record(BlogPost $post, ?int $actorUserId): BlogPostRevision
    {
        $post->load(['categories', 'tags', 'authors']);

        $next = (int) BlogPostRevision::query()->where('blog_post_id', $post->id)->max('revision_number') + 1;

        return BlogPostRevision::query()->create([
            'blog_post_id' => $post->id,
            'revision_number' => $next,
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'content' => $post->content,
            'featured_media_id' => $post->featured_media_id,
            'status_snapshot' => $post->status->value,
            'seo_snapshot' => [
                'seo_title' => $post->seo_title,
                'seo_description' => $post->seo_description,
                'canonical_url' => $post->canonical_url,
                'og_title' => $post->og_title,
                'og_description' => $post->og_description,
                'og_media_id' => $post->og_media_id,
                'robots_index' => (bool) $post->robots_index,
                'robots_follow' => (bool) $post->robots_follow,
            ],
            'taxonomy_snapshot' => [
                'categories' => $post->categories->map(fn (BlogCategory $c): array => [
                    'id' => (int) $c->id,
                    'is_primary' => (bool) $c->pivot->is_primary,
                ])->values()->all(),
                'tag_ids' => $post->tags->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            ],
            'author_snapshot' => $post->authors->map(fn (BlogAuthor $a): array => [
                'author_id' => (int) $a->id,
                'role' => (string) $a->pivot->role,
            ])->values()->all(),
            'created_by_user_id' => $actorUserId,
        ]);
    }
}
