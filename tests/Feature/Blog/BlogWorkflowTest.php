<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use Illuminate\Support\Facades\Event;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Events\BlogPostArchivedEvent;
use Modules\Blog\Domain\Events\BlogPostPublishedEvent;
use Modules\Blog\Domain\Events\BlogPostUnpublishedEvent;
use Modules\Blog\Domain\Models\BlogPostRevision;

/**
 * Editorial workflow: the transition map, publication timestamps, idempotent
 * publish, scheduled publishing/unpublishing, archive, and permissions.
 */
class BlogWorkflowTest extends BlogTestCase
{
    /** @test */
    public function the_full_happy_path_draft_to_published(): void
    {
        Event::fake([BlogPostPublishedEvent::class]);
        $post = $this->makePost(BlogPostStatus::Draft);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/submit-review")->assertOk()->assertJsonPath('data.status', 'in_review');
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertNotFound();

        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.is_publicly_visible', true);

        $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertOk();

        $fresh = $post->fresh();
        $this->assertNotNull($fresh->published_at);
        $this->assertNotNull($fresh->first_published_at);
        $this->assertNotNull($fresh->last_published_at);

        foreach (['submitted_for_review', 'approved', 'published'] as $action) {
            $this->assertDatabaseHas('blog_post_activity', ['blog_post_id' => $post->id, 'action' => $action]);
        }

        Event::assertDispatched(BlogPostPublishedEvent::class, fn (BlogPostPublishedEvent $e) => $e->postId === $post->id && $e->isFirstPublication);
    }

    /** @test */
    public function in_review_can_be_returned_to_draft_with_a_reason(): void
    {
        $post = $this->makePost(BlogPostStatus::InReview);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/return-to-draft", ['reason' => 'Needs sources'])
            ->assertOk()->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('blog_post_activity', ['blog_post_id' => $post->id, 'action' => 'returned_to_draft']);
    }

    /** @test */
    public function illegal_transitions_are_422(): void
    {
        $draft = $this->makePost(BlogPostStatus::Draft);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/blog/posts/{$draft->id}/publish")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/admin/blog/posts/{$draft->id}/approve")->assertStatus(422);
        $this->postJson("/api/v1/admin/blog/posts/{$draft->id}/unpublish")->assertStatus(422);
        $this->assertSame(BlogPostStatus::Draft, $draft->fresh()->status);
    }

    /** @test */
    public function publishing_is_idempotent(): void
    {
        Event::fake([BlogPostPublishedEvent::class]);
        $post = $this->makePost(BlogPostStatus::Approved);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/publish")->assertOk();
        $firstPublishedAt = $post->fresh()->published_at;
        $revisions = BlogPostRevision::query()->where('blog_post_id', $post->id)->count();

        $this->travel(5)->minutes();
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/publish")->assertOk()->assertJsonPath('data.status', 'published');

        $this->assertTrue($firstPublishedAt->equalTo($post->fresh()->published_at));
        $this->assertSame($revisions, BlogPostRevision::query()->where('blog_post_id', $post->id)->count());
        Event::assertDispatchedTimes(BlogPostPublishedEvent::class, 1);
    }

    /** @test */
    public function first_published_at_is_set_once_and_last_published_at_tracks_republication(): void
    {
        Event::fake([BlogPostPublishedEvent::class, BlogPostUnpublishedEvent::class]);
        $post = $this->makePost(BlogPostStatus::Approved);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/publish")->assertOk();
        $first = $post->fresh()->first_published_at;

        $this->travel(2)->days();
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/unpublish")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertNotFound();

        $this->travel(1)->days();
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/publish")->assertOk();

        $fresh = $post->fresh();
        $this->assertTrue($first->equalTo($fresh->first_published_at));
        $this->assertTrue($fresh->last_published_at->greaterThan($first));
        Event::assertDispatched(BlogPostPublishedEvent::class, fn ($e) => $e->isFirstPublication === false);
        Event::assertDispatched(BlogPostUnpublishedEvent::class, fn ($e) => $e->trigger === 'manual');
    }

    /** @test */
    public function a_scheduled_post_is_private_until_the_scheduler_publishes_it_when_due(): void
    {
        Event::fake([BlogPostPublishedEvent::class]);
        $post = $this->makePost(BlogPostStatus::Approved);
        $this->actingAsAdmin();

        $when = now()->addHours(2)->startOfMinute();
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/schedule", ['published_at' => $when->toIso8601String()])
            ->assertOk()->assertJsonPath('data.status', 'scheduled');

        $this->artisan('blogs:publish-scheduled')->assertSuccessful();
        $this->assertSame(BlogPostStatus::Scheduled, $post->fresh()->status);
        $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertNotFound();

        $this->travelTo($when->copy()->addMinute());
        $this->artisan('blogs:publish-scheduled')->assertSuccessful();

        $fresh = $post->fresh();
        $this->assertSame(BlogPostStatus::Published, $fresh->status);
        $this->assertTrue($when->equalTo($fresh->published_at), 'the scheduled time is kept as published_at');
        $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertOk();

        // Running again does nothing new.
        $this->artisan('blogs:publish-scheduled')->assertSuccessful();
        Event::assertDispatchedTimes(BlogPostPublishedEvent::class, 1);
    }

    /** @test */
    public function scheduling_requires_a_future_time_and_an_approved_post(): void
    {
        $approved = $this->makePost(BlogPostStatus::Approved);
        $draft = $this->makePost(BlogPostStatus::Draft);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/blog/posts/{$approved->id}/schedule", ['published_at' => now()->subDay()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('published_at');
        $this->postJson("/api/v1/admin/blog/posts/{$draft->id}/schedule", ['published_at' => now()->addDay()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    /** @test */
    public function unpublish_at_hides_the_post_on_time_and_the_scheduler_unpublishes_idempotently(): void
    {
        Event::fake([BlogPostUnpublishedEvent::class]);
        $post = $this->makePost(BlogPostStatus::Published, ['unpublish_at' => now()->addHour()]);

        $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertOk();

        $this->travel(61)->minutes();
        // Hidden immediately by the visibility rule, even before the cron runs.
        $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertNotFound();

        $this->artisan('blogs:publish-scheduled')->assertSuccessful();
        $this->artisan('blogs:publish-scheduled')->assertSuccessful();

        $fresh = $post->fresh();
        $this->assertSame(BlogPostStatus::Approved, $fresh->status);
        $this->assertNull($fresh->unpublish_at);
        Event::assertDispatchedTimes(BlogPostUnpublishedEvent::class, 1);
        Event::assertDispatched(BlogPostUnpublishedEvent::class, fn ($e) => $e->trigger === 'scheduled');
    }

    /** @test */
    public function archive_hides_a_live_post_and_archived_returns_to_draft(): void
    {
        Event::fake([BlogPostArchivedEvent::class]);
        $post = $this->makePost(BlogPostStatus::Published);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
        $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertNotFound();
        Event::assertDispatched(BlogPostArchivedEvent::class, fn ($e) => $e->wasPublic === true && $e->postId === $post->id);

        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/archive")->assertStatus(422);
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/return-to-draft")->assertOk()->assertJsonPath('data.status', 'draft');
    }

    /** @test */
    public function each_workflow_step_requires_its_own_permission(): void
    {
        $post = $this->makePost(BlogPostStatus::Draft);

        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/submit-review")->assertUnauthorized();

        $this->actingAsCustomer();
        foreach (['submit-review', 'approve', 'publish', 'unpublish', 'archive', 'return-to-draft'] as $step) {
            $this->postJson("/api/v1/admin/blog/posts/{$post->id}/{$step}")->assertForbidden();
        }

        // submit-review alone is not enough on someone else's post…
        $this->actingWithPermissions('blog.post.submit-review');
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/submit-review")->assertForbidden();

        // …but it is with update-any (an editor), and works with exact permissions only.
        $this->actingWithPermissions('blog.post.submit-review', 'blog.post.update-any');
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/submit-review")->assertOk();

        $this->actingWithPermissions('blog.post.approve');
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/publish")->assertForbidden();
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/approve")->assertOk();

        $this->actingWithPermissions('blog.post.publish');
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/archive")->assertForbidden();
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/publish")->assertOk();

        $this->actingWithPermissions('blog.post.archive');
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/archive")->assertOk();

        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/blog/posts/999999/publish')->assertNotFound();
    }

    /** @test */
    public function an_author_with_submit_review_may_submit_their_own_post(): void
    {
        $writer = $this->actingWithPermissions('blog.post.submit-review');
        $post = $this->makePost(BlogPostStatus::Draft, ['created_by_user_id' => $writer->id]);

        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/submit-review")->assertOk()->assertJsonPath('data.status', 'in_review');
    }
}
