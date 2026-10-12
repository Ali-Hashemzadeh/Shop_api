<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Blog\Application\Actions\PublishBlogPostAction;
use Modules\Blog\Domain\Enums\BlogCommentStatus;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Events\BlogCommentApprovedEvent;
use Modules\Blog\Domain\Events\BlogPostLikedEvent;
use Modules\Blog\Domain\Events\BlogPostPublishedEvent;
use Modules\Blog\Domain\Models\BlogComment;
use Modules\Blog\Domain\Models\BlogSubscription;
use Modules\Identity\Domain\Models\User;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Blog's published integration events: primitives only, immutable event ids,
 * dispatched at the right commit boundary, and consumed by Notification and
 * Analytics without either module touching Blog's tables (or vice versa).
 */
class BlogIntegrationEventsTest extends BlogTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNotificationPermissions();
        $this->seedAnalyticsPermissions();
    }

    /** @test */
    public function every_blog_event_carries_primitives_only_and_an_event_id(): void
    {
        $files = glob(base_path('Modules/Blog/Domain/Events/*.php'));
        $this->assertGreaterThanOrEqual(9, count($files));

        foreach ($files as $file) {
            $class = 'Modules\\Blog\\Domain\\Events\\'.basename($file, '.php');
            $reflection = new ReflectionClass($class);

            $this->assertTrue($reflection->hasProperty('eventId'), "{$class} has no eventId");

            foreach ($reflection->getProperties() as $property) {
                $type = $property->getType();
                $this->assertInstanceOf(ReflectionNamedType::class, $type, "{$class}::{$property->getName()} must be typed");
                $this->assertTrue($type->isBuiltin(), "{$class}::{$property->getName()} is not a primitive ({$type->getName()})");
                $this->assertTrue($property->isReadOnly(), "{$class}::{$property->getName()} must be readonly");
            }
        }
    }

    /** @test */
    public function publishing_notifies_followers_once_in_app_only(): void
    {
        $category = $this->makeCategory();
        $tag = $this->makeTag();
        $author = $this->makeAuthor();
        $post = $this->makePost(BlogPostStatus::Approved, ['title' => 'Big news'], ['categories' => [$category], 'tags' => [$tag], 'authors' => [$author]]);

        $followsCategory = User::factory()->create();
        $followsBoth = User::factory()->create();
        $stranger = User::factory()->create();
        BlogSubscription::query()->create(['user_id' => $followsCategory->id, 'subject_type' => 'category', 'subject_id' => $category->id]);
        BlogSubscription::query()->create(['user_id' => $followsBoth->id, 'subject_type' => 'tag', 'subject_id' => $tag->id]);
        BlogSubscription::query()->create(['user_id' => $followsBoth->id, 'subject_type' => 'author', 'subject_id' => $author->id]);

        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/publish")->assertOk();

        $this->assertDatabaseHas('notifications', ['user_id' => $followsCategory->id, 'type' => 'blog_post_published']);
        $this->assertSame(1, DB::table('notifications')->where('user_id', $followsBoth->id)->count());
        $this->assertDatabaseMissing('notifications', ['user_id' => $stranger->id]);
        $this->assertDatabaseMissing('notification_deliveries', ['channel' => 'sms']);

        // Republishing later does not re-notify.
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/unpublish")->assertOk();
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/publish")->assertOk();
        $this->assertSame(2, DB::table('notifications')->where('type', 'blog_post_published')->count());
    }

    /** @test */
    public function a_rolled_back_publication_notifies_nobody(): void
    {
        $category = $this->makeCategory();
        $post = $this->makePost(BlogPostStatus::Approved, [], ['categories' => [$category]]);
        $follower = User::factory()->create();
        BlogSubscription::query()->create(['user_id' => $follower->id, 'subject_type' => 'category', 'subject_id' => $category->id]);

        try {
            DB::transaction(function () use ($post): void {
                app(PublishBlogPostAction::class)->handle($post, null);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(BlogPostStatus::Approved, $post->fresh()->status);
        $this->assertDatabaseMissing('notifications', ['user_id' => $follower->id]);
    }

    /** @test */
    public function comment_approval_and_replies_notify_their_authors(): void
    {
        $post = $this->makePost(BlogPostStatus::Published);
        $parentAuthor = User::factory()->create();
        $replier = User::factory()->create();
        $parent = BlogComment::createWithPublicCode(['blog_post_id' => $post->id, 'user_id' => $parentAuthor->id, 'body' => 'p', 'status' => 'approved', 'approved_at' => now()]);
        $reply = BlogComment::createWithPublicCode(['blog_post_id' => $post->id, 'user_id' => $replier->id, 'parent_id' => $parent->id, 'body' => 'r', 'status' => BlogCommentStatus::Pending->value]);
        $selfReply = BlogComment::createWithPublicCode(['blog_post_id' => $post->id, 'user_id' => $parentAuthor->id, 'parent_id' => $parent->id, 'body' => 's', 'status' => BlogCommentStatus::Pending->value]);

        $this->actingWithPermissions('blog.comment.moderate');
        $this->postJson("/api/v1/admin/blog/comments/{$reply->id}/approve")->assertOk();
        $this->postJson("/api/v1/admin/blog/comments/{$selfReply->id}/approve")->assertOk();

        $this->assertDatabaseHas('notifications', ['user_id' => $replier->id, 'type' => 'blog_comment_approved']);
        $this->assertSame(1, DB::table('notifications')->where('user_id', $parentAuthor->id)->where('type', 'blog_comment_reply')->count(), 'replying to yourself is not a notification');
    }

    /** @test */
    public function analytics_consumes_blog_events_idempotently(): void
    {
        $post = $this->makePost(BlogPostStatus::Published, ['slug' => 'tracked']);
        $user = User::factory()->create();
        $user->assignRole('customer');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/blog/posts/tracked/like')->assertOk();
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 Chrome/120'])->postJson('/api/v1/blog/posts/tracked/views')->assertStatus(202);

        $this->assertDatabaseHas('analytics_blog_daily_stats', ['blog_post_id' => $post->id, 'likes' => 1, 'views' => 1]);

        // A duplicated delivery of the same event is counted once.
        $event = new BlogPostLikedEvent($post->id, $user->id, now()->toIso8601String(), 'fixed-event-id');
        Event::dispatch($event);
        Event::dispatch($event);
        $this->assertSame(2, (int) DB::table('analytics_blog_daily_stats')->where('blog_post_id', $post->id)->value('likes'));

        // Approved comments are counted; submissions are not.
        $comment = BlogComment::createWithPublicCode(['blog_post_id' => $post->id, 'user_id' => $user->id, 'body' => 'c', 'status' => 'pending']);
        $this->assertSame(0, (int) DB::table('analytics_blog_daily_stats')->where('blog_post_id', $post->id)->value('comments'));
        Event::dispatch(new BlogCommentApprovedEvent($comment->id, $comment->public_code, $user->id, $post->id, $post->slug, $post->title, false, now()->toIso8601String()));
        $this->assertSame(1, (int) DB::table('analytics_blog_daily_stats')->where('blog_post_id', $post->id)->value('comments'));

        $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/analytics/blog?metric=likes')
            ->assertOk()
            ->assertJsonPath('data.summary.likes', 2)
            ->assertJsonPath('data.top_posts.0.blog_post_id', $post->id);

        $this->actingAsCustomer();
        $this->getJson('/api/v1/admin/analytics/blog')->assertForbidden();
    }

    /** @test */
    public function the_blog_engagement_report_uses_blogs_own_counters(): void
    {
        $category = $this->makeCategory(['name' => 'Guides']);
        $post = $this->makePost(BlogPostStatus::Published, ['title' => 'Top'], ['categories' => [$category]]);
        DB::table('blog_post_stats')->where('blog_post_id', $post->id)->update(['views_count' => 40, 'likes_count' => 3]);
        $this->makePost(BlogPostStatus::Published, ['title' => 'Low']);

        $this->getJson('/api/v1/admin/blog/analytics')->assertUnauthorized();
        $this->actingAsCustomer();
        $this->getJson('/api/v1/admin/blog/analytics')->assertForbidden();

        $this->actingWithPermissions('blog.analytics.view');
        $this->getJson('/api/v1/admin/blog/analytics?metric=views_count')
            ->assertOk()
            ->assertJsonPath('data.totals.views_count', 40)
            ->assertJsonPath('data.top_posts.0.title', 'Top')
            ->assertJsonPath('data.top_categories.0.name', 'Guides')
            ->assertJsonPath('data.top_categories.0.total', 40);
        $this->getJson('/api/v1/admin/blog/analytics?metric=nope')->assertStatus(422);
    }

    /** @test */
    public function publication_event_payload_lists_only_active_taxonomy(): void
    {
        Event::fake([BlogPostPublishedEvent::class]);
        $active = $this->makeCategory();
        $inactive = $this->makeCategory(['is_active' => false]);
        $post = $this->makePost(BlogPostStatus::Approved, [], ['categories' => [$active, $inactive]]);

        app(PublishBlogPostAction::class)->handle($post, null);

        Event::assertDispatched(BlogPostPublishedEvent::class, fn ($e) => $e->categoryIds === [$active->id] && $e->postPublicCode === $post->public_code);
    }
}
