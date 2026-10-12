<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Blog\Application\Support\BlogActivityLogger;
use Modules\Blog\Domain\Enums\BlogActivityAction;
use Modules\Blog\Domain\Models\BlogAuthor;
use Modules\Blog\Domain\Models\BlogCategory;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostRevision;
use Modules\Blog\Domain\Models\BlogTag;

/**
 * Restore an old revision by saving its content as a NEW current version —
 * history is never rewound or rewritten. Goes through UpdateBlogPostAction, so
 * optimistic locking (409), slug-history handling, sanitization, the new
 * revision row, and the audit entry all behave exactly like a manual save.
 *
 * Taxonomy and authors referenced by the snapshot that no longer exist are
 * skipped; if none of the snapshot's authors survive, current authorship is
 * kept rather than leaving the post authorless. The status is not restored —
 * restoring content never publishes or unpublishes anything.
 */
final class RestoreBlogPostRevisionAction
{
    public function __construct(
        private readonly UpdateBlogPostAction $update,
        private readonly BlogActivityLogger $activity,
    ) {}

    public function handle(BlogPost $post, BlogPostRevision $revision, int $expectedVersion, int $actorUserId): BlogPost
    {
        return DB::transaction(function () use ($post, $revision, $expectedVersion, $actorUserId): BlogPost {
            $seo = (array) ($revision->seo_snapshot ?? []);
            $taxonomy = (array) ($revision->taxonomy_snapshot ?? []);

            $data = [
                'title' => $revision->title,
                'excerpt' => $revision->excerpt,
                'content' => (string) $revision->content,
                'featured_media_id' => $revision->featured_media_id,
                'seo_title' => $seo['seo_title'] ?? null,
                'seo_description' => $seo['seo_description'] ?? null,
                'canonical_url' => $seo['canonical_url'] ?? null,
                'og_title' => $seo['og_title'] ?? null,
                'og_description' => $seo['og_description'] ?? null,
                'og_media_id' => $seo['og_media_id'] ?? null,
                'robots_index' => (bool) ($seo['robots_index'] ?? true),
                'robots_follow' => (bool) ($seo['robots_follow'] ?? true),
            ];

            if ($revision->slug !== $post->slug && $this->slugIsFreeFor($revision->slug, (int) $post->id)) {
                $data['slug'] = $revision->slug;
            }

            $categories = collect($taxonomy['categories'] ?? []);
            $existingCategories = BlogCategory::query()->whereIn('id', $categories->pluck('id')->all())->pluck('id')->map(fn ($id) => (int) $id)->all();
            $data['category_ids'] = $categories->pluck('id')->map(fn ($id) => (int) $id)->filter(fn (int $id) => in_array($id, $existingCategories, true))->values()->all();
            $primary = $categories->firstWhere('is_primary', true);
            $data['primary_category_id'] = $primary !== null && in_array((int) $primary['id'], $existingCategories, true) ? (int) $primary['id'] : null;

            $data['tag_ids'] = BlogTag::query()->whereIn('id', (array) ($taxonomy['tag_ids'] ?? []))->pluck('id')->map(fn ($id) => (int) $id)->all();

            $authors = collect((array) ($revision->author_snapshot ?? []));
            $existingAuthors = BlogAuthor::query()->whereIn('id', $authors->pluck('author_id')->all())->pluck('id')->map(fn ($id) => (int) $id)->all();
            $survivingAuthors = $authors->filter(fn (array $a) => in_array((int) $a['author_id'], $existingAuthors, true))->values()->all();
            if ($survivingAuthors !== []) {
                $data['authors'] = $survivingAuthors;
            }

            $restored = $this->update->handle($post, $data, $expectedVersion, $actorUserId);

            $this->activity->log($restored, BlogActivityAction::RevisionRestored, $actorUserId, [
                'revision_id' => (int) $revision->id,
                'revision_number' => (int) $revision->revision_number,
            ]);

            return $restored;
        });
    }

    private function slugIsFreeFor(string $slug, int $postId): bool
    {
        return ! BlogPost::withTrashed()->where('slug', $slug)->where('id', '!=', $postId)->exists()
            && ! DB::table('blog_post_slug_history')->where('slug', $slug)->where('blog_post_id', '!=', $postId)->exists();
    }
}
