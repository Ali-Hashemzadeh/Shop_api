<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Blog\Application\Support\BlogActivityLogger;
use Modules\Blog\Application\Support\BlogPostPersister;
use Modules\Blog\Application\Support\BlogRevisionRecorder;
use Modules\Blog\Domain\Enums\BlogActivityAction;
use Modules\Blog\Domain\Exceptions\BlogPostVersionConflictException;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostAutosave;

/**
 * A manual save. Optimistic locking: the row is locked and its `version`
 * compared with the version the editor started from; a mismatch is a 409 and
 * nothing is written. A successful save increments `version` atomically, records
 * an official revision when revisioned content changed, logs `updated` (plus
 * `slug_changed` / `featured` / `unfeatured` where relevant), and clears the
 * editor's autosave — the manual save supersedes it.
 *
 * The slug never follows the title automatically: it changes only when the
 * editor sends a new `slug`.
 */
final class UpdateBlogPostAction
{
    public function __construct(
        private readonly BlogPostPersister $persister,
        private readonly BlogRevisionRecorder $revisions,
        private readonly BlogActivityLogger $activity,
    ) {}

    public function handle(BlogPost $post, array $data, int $expectedVersion, int $actorUserId): BlogPost
    {
        return DB::transaction(function () use ($post, $data, $expectedVersion, $actorUserId): BlogPost {
            /** @var BlogPost $locked */
            $locked = BlogPost::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->version !== $expectedVersion) {
                throw new BlogPostVersionConflictException($expectedVersion, (int) $locked->version);
            }

            $wasFeatured = (bool) $locked->is_featured;
            $changed = $this->persister->applyAttributes($locked, $data);
            $previousSlug = filled($data['slug'] ?? null) ? $this->persister->applySlug($locked, (string) $data['slug']) : null;

            $locked->updated_by_user_id = $actorUserId;
            $locked->version = $expectedVersion + 1;

            // Conditional write as a second guard: zero rows means a concurrent
            // writer won between our lock and here (engines without row locks).
            $affected = BlogPost::query()
                ->whereKey($locked->id)
                ->where('version', $expectedVersion)
                ->update($locked->getDirty() + ['updated_at' => now()]);

            if ($affected === 0) {
                throw new BlogPostVersionConflictException($expectedVersion, (int) BlogPost::query()->whereKey($locked->id)->value('version'));
            }

            $locked = $locked->fresh();
            $this->persister->syncRelations($locked, $data);
            $locked = $locked->fresh();

            if (BlogPostPersister::touchesRevisionedContent($changed, $data, $previousSlug !== null)) {
                $this->revisions->record($locked, $actorUserId);
            }

            $this->activity->log($locked, BlogActivityAction::Updated, $actorUserId, [
                'fields' => array_values(array_unique([...$changed, ...array_keys(array_intersect_key($data, array_flip([
                    'category_ids', 'primary_category_id', 'tag_ids', 'authors', 'media', 'products', 'related_post_ids',
                ])))])),
                'version' => (int) $locked->version,
            ]);

            if ($previousSlug !== null) {
                $this->activity->log($locked, BlogActivityAction::SlugChanged, $actorUserId, [
                    'from' => $previousSlug,
                    'to' => $locked->slug,
                ]);
            }

            if ($wasFeatured !== (bool) $locked->is_featured) {
                $this->activity->log(
                    $locked,
                    $locked->is_featured ? BlogActivityAction::Featured : BlogActivityAction::Unfeatured,
                    $actorUserId,
                );
            }

            BlogPostAutosave::query()->where('blog_post_id', $locked->id)->where('user_id', $actorUserId)->delete();

            return $locked;
        });
    }
}
