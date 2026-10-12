<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Models\BlogCollection;
use Modules\Blog\Domain\Models\BlogPlacement;

/**
 * Editorial collections and keyed placements: curated order, date windows,
 * and no unpublished article ever exposed publicly.
 */
class BlogCurationTest extends BlogTestCase
{
    /** @test */
    public function collections_keep_curated_order_and_hide_unpublished_posts(): void
    {
        $a = $this->makePost(BlogPostStatus::Published, ['title' => 'A']);
        $b = $this->makePost(BlogPostStatus::Published, ['title' => 'B']);
        $draft = $this->makePost(BlogPostStatus::Draft, ['title' => 'Draft']);
        $this->actingAsAdmin();

        $collection = $this->postJson('/api/v1/admin/blog/collections', [
            'name' => "Editor's Picks",
            'post_ids' => [$b->id, $draft->id, $a->id],
        ])->assertCreated()->assertJsonPath('data.post_ids', [$b->id, $draft->id, $a->id])->json('data');

        $this->getJson("/api/v1/blog/collections/{$collection['slug']}")
            ->assertOk()
            ->assertJsonCount(2, 'data.posts')
            ->assertJsonPath('data.posts.0.title', 'B')
            ->assertJsonPath('data.posts.1.title', 'A');

        $this->patchJson("/api/v1/admin/blog/collections/{$collection['id']}", ['post_ids' => [$a->id]])->assertOk();
        $this->getJson("/api/v1/blog/collections/{$collection['slug']}")->assertJsonCount(1, 'data.posts');
    }

    /** @test */
    public function collections_respect_active_flag_and_date_window(): void
    {
        BlogCollection::query()->create(['name' => 'Live', 'slug' => 'live', 'is_active' => true]);
        BlogCollection::query()->create(['name' => 'Off', 'slug' => 'off', 'is_active' => false]);
        BlogCollection::query()->create(['name' => 'Future', 'slug' => 'future', 'is_active' => true, 'starts_at' => now()->addDay()]);
        BlogCollection::query()->create(['name' => 'Past', 'slug' => 'past', 'is_active' => true, 'ends_at' => now()->subDay()]);

        $this->getJson('/api/v1/blog/collections')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'live');
        foreach (['off', 'future', 'past'] as $slug) {
            $this->getJson("/api/v1/blog/collections/{$slug}")->assertNotFound();
        }
    }

    /** @test */
    public function collection_validation(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/blog/collections', [])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/admin/blog/collections', [
            'name' => 'Bad window', 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('ends_at');
        $this->postJson('/api/v1/admin/blog/collections', ['name' => 'Ghost posts', 'post_ids' => [999999]])
            ->assertStatus(422)->assertJsonValidationErrors('post_ids.0');
    }

    /** @test */
    public function placements_are_ordered_windowed_and_never_leak_unpublished_posts(): void
    {
        $first = $this->makePost(BlogPostStatus::Published, ['title' => 'First']);
        $second = $this->makePost(BlogPostStatus::Published, ['title' => 'Second']);
        $draft = $this->makePost(BlogPostStatus::Draft, ['title' => 'Draft']);
        $expired = $this->makePost(BlogPostStatus::Published, ['title' => 'Expired slot']);
        $future = $this->makePost(BlogPostStatus::Published, ['title' => 'Future slot']);

        BlogPlacement::query()->create(['placement_key' => 'blog.home.hero', 'blog_post_id' => $second->id, 'sort_order' => 2]);
        BlogPlacement::query()->create(['placement_key' => 'blog.home.hero', 'blog_post_id' => $first->id, 'sort_order' => 1]);
        BlogPlacement::query()->create(['placement_key' => 'blog.home.hero', 'blog_post_id' => $draft->id, 'sort_order' => 0]);
        BlogPlacement::query()->create(['placement_key' => 'blog.home.hero', 'blog_post_id' => $expired->id, 'sort_order' => 0, 'ends_at' => now()->subMinute()]);
        BlogPlacement::query()->create(['placement_key' => 'blog.home.hero', 'blog_post_id' => $future->id, 'sort_order' => 0, 'starts_at' => now()->addDay()]);
        BlogPlacement::query()->create(['placement_key' => 'blog.home.secondary', 'blog_post_id' => $first->id]);

        $hero = $this->getJson('/api/v1/blog/placements/blog.home.hero')->assertOk()->assertJsonCount(2, 'data');
        $hero->assertJsonPath('data.0.post.title', 'First')->assertJsonPath('data.1.post.title', 'Second');
        $this->assertArrayNotHasKey('blog_post_id', $hero->json('data.0'));

        $this->assertCount(2, $this->getJson('/api/v1/blog')->json('data.placements')['blog.home.hero']);

        // Once the draft is published it appears in its slot without any admin action.
        $draft->update(['status' => 'published', 'published_at' => now()->subMinute()]);
        $this->getJson('/api/v1/blog/placements/blog.home.hero')->assertJsonCount(3, 'data')->assertJsonPath('data.0.post.title', 'Draft');
    }

    /** @test */
    public function admins_manage_placements_with_keys_not_schema(): void
    {
        $post = $this->makePost(BlogPostStatus::Published);
        $this->actingAsAdmin();

        $placement = $this->postJson('/api/v1/admin/blog/placements', [
            'placement_key' => 'blog.category.phones.top', 'blog_post_id' => $post->id, 'sort_order' => 3,
        ])->assertCreated()->assertJsonPath('data.blog_post_id', $post->id)->assertJsonPath('data.is_active_now', true)->json('data');

        $this->postJson('/api/v1/admin/blog/placements', ['placement_key' => 'Bad Key!', 'blog_post_id' => $post->id])
            ->assertStatus(422)->assertJsonValidationErrors('placement_key');
        $this->postJson('/api/v1/admin/blog/placements', ['placement_key' => 'blog.x', 'blog_post_id' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors('blog_post_id');

        $this->patchJson("/api/v1/admin/blog/placements/{$placement['id']}", ['ends_at' => now()->subMinute()->toIso8601String(), 'starts_at' => now()->subHour()->toIso8601String()])
            ->assertOk()->assertJsonPath('data.is_active_now', false);
        $this->getJson('/api/v1/admin/blog/placements?placement_key=blog.category.phones.top')->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/admin/blog/placements/{$placement['id']}")->assertNoContent();
    }

    /** @test */
    public function curation_permissions(): void
    {
        $post = $this->makePost(BlogPostStatus::Published);

        $this->postJson('/api/v1/admin/blog/collections', ['name' => 'x'])->assertUnauthorized();
        $this->actingAsCustomer();
        $this->postJson('/api/v1/admin/blog/collections', ['name' => 'x'])->assertForbidden();
        $this->postJson('/api/v1/admin/blog/placements', ['placement_key' => 'a.b', 'blog_post_id' => $post->id])->assertForbidden();

        $this->actingWithPermissions('blog.collection.manage');
        $id = $this->postJson('/api/v1/admin/blog/collections', ['name' => 'Gift guides'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/blog/placements', ['placement_key' => 'a.b', 'blog_post_id' => $post->id])->assertForbidden();
        $this->deleteJson("/api/v1/admin/blog/collections/{$id}")->assertNoContent();

        $this->actingWithPermissions('blog.placement.manage');
        $this->postJson('/api/v1/admin/blog/placements', ['placement_key' => 'a.b', 'blog_post_id' => $post->id])->assertCreated();
    }
}
