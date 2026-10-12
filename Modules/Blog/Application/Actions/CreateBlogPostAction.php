<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Blog\Application\Support\BlogActivityLogger;
use Modules\Blog\Application\Support\BlogPostPersister;
use Modules\Blog\Application\Support\BlogPostStats;
use Modules\Blog\Application\Support\BlogRevisionRecorder;
use Modules\Blog\Domain\Enums\BlogActivityAction;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Services\BlogSlugger;

/**
 * Create a draft article with its taxonomy, authors, media, products, and
 * related posts in one transaction, then record revision #1 and the `created`
 * audit entry. The public code is minted server-side (createWithPublicCode) and
 * never read from input; the slug defaults to a unique slug of the title.
 */
final class CreateBlogPostAction
{
    public function __construct(
        private readonly BlogPostPersister $persister,
        private readonly BlogRevisionRecorder $revisions,
        private readonly BlogActivityLogger $activity,
        private readonly BlogPostStats $stats,
    ) {}

    public function handle(array $data, int $actorUserId): BlogPost
    {
        return DB::transaction(function () use ($data, $actorUserId): BlogPost {
            $post = new BlogPost;
            $post->status = BlogPostStatus::Draft;
            $post->version = 1;
            $post->created_by_user_id = $actorUserId;
            $post->updated_by_user_id = $actorUserId;
            $this->persister->applyAttributes($post, $data);

            $slug = filled($data['slug'] ?? null)
                ? (string) $data['slug']
                : BlogSlugger::unique(
                    BlogSlugger::slugify((string) $data['title']),
                    fn (string $candidate) => BlogPost::withTrashed()->where('slug', $candidate)->exists()
                        || DB::table('blog_post_slug_history')->where('slug', $candidate)->exists(),
                );
            $this->persister->applySlug($post, $slug);

            $post = BlogPost::createWithPublicCode($post->getAttributes());

            $this->persister->syncRelations($post, $data);
            $this->stats->ensure((int) $post->id);

            $this->revisions->record($post, $actorUserId);
            $this->activity->log($post, BlogActivityAction::Created, $actorUserId);

            return $post->fresh();
        });
    }
}
