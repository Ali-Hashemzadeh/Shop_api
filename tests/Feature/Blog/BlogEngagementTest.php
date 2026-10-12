<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Events\BlogPostBookmarkedEvent;
use Modules\Blog\Domain\Events\BlogPostLikedEvent;
use Modules\Blog\Domain\Events\BlogPostViewedEvent;
use Modules\Blog\Domain\Events\BlogProductClickedEvent;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostStat;
use Modules\Blog\Domain\Models\BlogSubscription;
use Modules\Identity\Domain\Models\User;

/**
 * Reader engagement: idempotent likes/bookmarks with batched read-back, own
 * bookmarks list, follow-subscriptions with subject validation, deduplicated
 * bot-filtered view counting, and product click-through tracking.
 */
class BlogEngagementTest extends BlogTestCase
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36';

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();
        $this->post = $this->makePost(BlogPostStatus::Published, ['slug' => 'engaging']);
    }

    private function reader(): User
    {
        $user = User::factory()->create();
        $user->assignRole('customer');
        Sanctum::actingAs($user);

        return $user;
    }

    private function stat(string $counter): int
    {
        return (int) BlogPostStat::query()->whereKey($this->post->id)->value($counter);
    }

    /** @test */
    public function liking_is_idempotent_and_reflected_in_counts_and_is_liked(): void
    {
        Event::fake([BlogPostLikedEvent::class]);
        $this->reader();

        $this->postJson('/api/v1/blog/posts/engaging/like')->assertOk()->assertJson(['liked' => true, 'likes_count' => 1]);
        $this->postJson('/api/v1/blog/posts/engaging/like')->assertOk()->assertJson(['liked' => true, 'likes_count' => 1]);
        Event::assertDispatchedTimes(BlogPostLikedEvent::class, 1);

        $this->getJson('/api/v1/blog/posts/engaging')->assertJsonPath('data.is_liked', true)->assertJsonPath('data.likes_count', 1);
        $this->getJson('/api/v1/blog/posts')->assertJsonPath('data.0.is_liked', true);

        $this->deleteJson('/api/v1/blog/posts/engaging/like')->assertOk()->assertJson(['liked' => false, 'likes_count' => 0]);
        $this->deleteJson('/api/v1/blog/posts/engaging/like')->assertOk()->assertJson(['likes_count' => 0]);
        $this->assertSame(0, $this->stat('likes_count'));
    }

    /** @test */
    public function guests_see_is_liked_false_and_cannot_like(): void
    {
        $this->getJson('/api/v1/blog/posts/engaging')->assertOk()->assertJsonPath('data.is_liked', false)->assertJsonPath('data.is_bookmarked', false);
        $this->postJson('/api/v1/blog/posts/engaging/like')->assertUnauthorized();
        $this->postJson('/api/v1/blog/posts/engaging/bookmark')->assertUnauthorized();
        $this->getJson('/api/v1/blog/bookmarks')->assertUnauthorized();
        $this->getJson('/api/v1/blog/subscriptions')->assertUnauthorized();
        $this->postJson('/api/v1/blog/subscriptions', ['subject_type' => 'tag', 'subject_id' => 1])->assertUnauthorized();
    }

    /** @test */
    public function engagement_targets_must_be_publicly_visible(): void
    {
        $draft = $this->makePost(BlogPostStatus::Draft, ['slug' => 'not-yet']);
        $this->reader();

        $this->postJson('/api/v1/blog/posts/not-yet/like')->assertNotFound();
        $this->postJson('/api/v1/blog/posts/not-yet/bookmark')->assertNotFound();
        $this->assertSame(0, (int) BlogPostStat::query()->whereKey($draft->id)->value('likes_count'));
    }

    /** @test */
    public function bookmarks_are_idempotent_and_the_list_is_caller_scoped_and_public_only(): void
    {
        Event::fake([BlogPostBookmarkedEvent::class]);
        $other = $this->makePost(BlogPostStatus::Published, ['slug' => 'other']);
        $soonHidden = $this->makePost(BlogPostStatus::Published, ['slug' => 'soon-hidden']);

        $someoneElse = $this->reader();
        $this->postJson('/api/v1/blog/posts/other/bookmark')->assertOk();

        $me = $this->reader();
        $this->postJson('/api/v1/blog/posts/engaging/bookmark')->assertOk()->assertJson(['bookmarked' => true]);
        $this->postJson('/api/v1/blog/posts/engaging/bookmark')->assertOk();
        $this->postJson('/api/v1/blog/posts/soon-hidden/bookmark')->assertOk();
        Event::assertDispatchedTimes(BlogPostBookmarkedEvent::class, 3);

        $soonHidden->update(['status' => 'archived']);

        $list = $this->getJson('/api/v1/blog/bookmarks')->assertOk()->assertJsonCount(1, 'data')->assertJsonStructure(['data', 'links', 'meta']);
        $list->assertJsonPath('data.0.id', $this->post->public_code)->assertJsonPath('data.0.is_bookmarked', true);

        $this->deleteJson('/api/v1/blog/posts/engaging/bookmark')->assertOk()->assertJson(['bookmarked' => false]);
        $this->getJson('/api/v1/blog/bookmarks')->assertJsonCount(0, 'data');
        $this->assertSame(0, $this->stat('bookmarks_count'));
        $this->assertNotSame($someoneElse->id, $me->id);
        $this->assertNotNull($other);
    }

    /** @test */
    public function subscriptions_validate_the_subject_and_are_idempotent(): void
    {
        $category = $this->makeCategory();
        $inactiveTag = $this->makeTag(['is_active' => false]);
        $author = $this->makeAuthor();
        $this->reader();

        $sub = $this->postJson('/api/v1/blog/subscriptions', ['subject_type' => 'category', 'subject_id' => $category->id])
            ->assertCreated()->assertJsonPath('data.subject.name', $category->name)->json('data');
        $this->postJson('/api/v1/blog/subscriptions', ['subject_type' => 'category', 'subject_id' => $category->id])
            ->assertOk()->assertJsonPath('data.id', $sub['id']);
        $this->postJson('/api/v1/blog/subscriptions', ['subject_type' => 'author', 'subject_id' => $author->id])->assertCreated();

        $this->postJson('/api/v1/blog/subscriptions', ['subject_type' => 'tag', 'subject_id' => $inactiveTag->id])
            ->assertStatus(422)->assertJsonValidationErrors('subject_id');
        $this->postJson('/api/v1/blog/subscriptions', ['subject_type' => 'category', 'subject_id' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors('subject_id');
        $this->postJson('/api/v1/blog/subscriptions', ['subject_type' => 'product', 'subject_id' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('subject_type');

        $this->getJson('/api/v1/blog/subscriptions')->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson("/api/v1/blog/subscriptions/{$sub['id']}")->assertNoContent();
        $this->getJson('/api/v1/blog/subscriptions')->assertJsonCount(1, 'data');
    }

    /** @test */
    public function another_readers_subscription_is_a_404(): void
    {
        $category = $this->makeCategory();
        $owner = User::factory()->create();
        $sub = BlogSubscription::query()->create(['user_id' => $owner->id, 'subject_type' => 'category', 'subject_id' => $category->id]);

        $this->deleteJson("/api/v1/blog/subscriptions/{$sub->id}")->assertUnauthorized();
        $this->reader();
        $this->deleteJson("/api/v1/blog/subscriptions/{$sub->id}")->assertNotFound();
        $this->assertDatabaseHas('blog_subscriptions', ['id' => $sub->id]);
    }

    /** @test */
    public function views_are_deduplicated_per_viewer_and_bots_are_ignored(): void
    {
        Event::fake([BlogPostViewedEvent::class]);

        $this->withHeaders(['User-Agent' => self::UA, 'X-Session-Id' => 'session-a'])
            ->postJson('/api/v1/blog/posts/engaging/views')->assertStatus(202)->assertJson(['recorded' => true]);
        $this->withHeaders(['User-Agent' => self::UA, 'X-Session-Id' => 'session-a'])
            ->postJson('/api/v1/blog/posts/engaging/views')->assertStatus(202)->assertJson(['recorded' => false]);
        $this->withHeaders(['User-Agent' => self::UA, 'X-Session-Id' => 'session-b'])
            ->postJson('/api/v1/blog/posts/engaging/views')->assertJson(['recorded' => true]);
        $this->withHeaders(['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)', 'X-Session-Id' => 'bot'])
            ->postJson('/api/v1/blog/posts/engaging/views')->assertJson(['recorded' => false]);

        $this->assertSame(2, $this->stat('views_count'));
        Event::assertDispatchedTimes(BlogPostViewedEvent::class, 2);

        // Reading the article never counts a view by itself.
        $this->withHeaders(['User-Agent' => self::UA])->getJson('/api/v1/blog/posts/engaging')->assertOk();
        $this->assertSame(2, $this->stat('views_count'));

        // Once the dedup window passes, the same viewer counts again.
        $this->travel((int) config('blog.engagement.view_dedup_minutes') + 1)->minutes();
        $this->withHeaders(['User-Agent' => self::UA, 'X-Session-Id' => 'session-a'])
            ->postJson('/api/v1/blog/posts/engaging/views')->assertJson(['recorded' => true]);
    }

    /** @test */
    public function product_clicks_require_a_linked_product_and_are_deduplicated(): void
    {
        Event::fake([BlogProductClickedEvent::class]);
        $product = $this->makeProduct();
        $unlinked = $this->makeProduct('Unlinked');
        $this->post->products()->create(['product_id' => $product->id, 'relation_type' => 'featured', 'sort_order' => 0]);

        $this->withHeaders(['User-Agent' => self::UA, 'X-Session-Id' => 'guest-1'])
            ->postJson('/api/v1/blog/posts/engaging/product-clicks', ['product_id' => $product->id, 'placement' => 'inline_card'])
            ->assertStatus(202)->assertJson(['recorded' => true]);
        $this->withHeaders(['User-Agent' => self::UA, 'X-Session-Id' => 'guest-1'])
            ->postJson('/api/v1/blog/posts/engaging/product-clicks', ['product_id' => $product->id])
            ->assertJson(['recorded' => false]);
        $this->withHeaders(['User-Agent' => self::UA])
            ->postJson('/api/v1/blog/posts/engaging/product-clicks', ['product_id' => $unlinked->id])
            ->assertStatus(422)->assertJsonValidationErrors('product_id');

        $this->assertSame(1, $this->stat('product_clicks_count'));
        Event::assertDispatched(BlogProductClickedEvent::class, function (BlogProductClickedEvent $e) use ($product) {
            return $e->productId === $product->id
                && $e->postId === $this->post->id
                && $e->userId === null
                && $e->placement === 'inline_card'
                && $e->sessionHash !== null && $e->sessionHash !== 'guest-1';
        });
    }

    /** @test */
    public function public_engagement_tracking_is_throttled_by_the_public_limiter(): void
    {
        $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/blog/posts/{slug}/views');
        $this->assertContains('throttle:public', $route->gatherMiddleware());
    }
}
