<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Models\BlogSubscription;
use Modules\Identity\Domain\Models\User;

/**
 * Category / tag / author management: hierarchy and cycle prevention, unique
 * slugs, safe deletion, loose Identity validation, and permissions.
 */
class BlogTaxonomyAdminTest extends BlogTestCase
{
    /** @test */
    public function categories_support_hierarchy_ordering_and_seo(): void
    {
        $this->actingAsAdmin();

        $root = $this->postJson('/api/v1/admin/blog/categories', [
            'name' => 'راهنمای خرید', 'sort_order' => 2, 'seo_title' => 'Guides SEO',
        ])->assertCreated()->assertJsonPath('data.slug', 'راهنمای-خرید')->json('data');

        $this->postJson('/api/v1/admin/blog/categories', ['name' => 'A first', 'sort_order' => 1])->assertCreated();
        $child = $this->postJson('/api/v1/admin/blog/categories', ['name' => 'Phones', 'parent_id' => $root['id']])
            ->assertCreated()->assertJsonPath('data.parent_id', $root['id'])->json('data');

        $tree = $this->getJson('/api/v1/blog/categories')->json('data');
        $this->assertSame(['A first', 'راهنمای خرید'], array_column($tree, 'name'));
        $this->assertSame($child['id'], $tree[1]['children'][0]['id']);

        $this->getJson('/api/v1/blog/categories/'.urlencode($root['slug']))
            ->assertOk()
            ->assertJsonPath('data.seo.title', 'Guides SEO')
            ->assertJsonPath('data.children.0.slug', 'phones');
    }

    /** @test */
    public function self_parenting_and_cycles_are_rejected(): void
    {
        $a = $this->makeCategory();
        $b = $this->makeCategory(['parent_id' => $a->id]);
        $c = $this->makeCategory(['parent_id' => $b->id]);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/blog/categories/{$a->id}", ['parent_id' => $a->id])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->patchJson("/api/v1/admin/blog/categories/{$a->id}", ['parent_id' => $c->id])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->patchJson("/api/v1/admin/blog/categories/{$c->id}", ['parent_id' => null])->assertOk()->assertJsonPath('data.parent_id', null);
    }

    /** @test */
    public function category_slugs_are_unique(): void
    {
        $this->makeCategory(['slug' => 'news']);
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/blog/categories', ['name' => 'News', 'slug' => 'news'])
            ->assertStatus(422)->assertJsonValidationErrors('slug');
        // Auto-generated slugs are deduplicated instead.
        $this->postJson('/api/v1/admin/blog/categories', ['name' => 'News'])->assertCreated()->assertJsonPath('data.slug', 'news-2');
    }

    /** @test */
    public function a_category_with_children_or_posts_cannot_be_deleted(): void
    {
        $parent = $this->makeCategory();
        $this->makeCategory(['parent_id' => $parent->id]);
        $used = $this->makeCategory();
        $this->makePost(BlogPostStatus::Draft, [], ['categories' => [$used]]);
        $free = $this->makeCategory();
        BlogSubscription::query()->create(['user_id' => 1, 'subject_type' => 'category', 'subject_id' => $free->id]);
        $this->actingAsAdmin();

        $this->deleteJson("/api/v1/admin/blog/categories/{$parent->id}")->assertStatus(422)->assertJsonValidationErrors('category');
        $this->deleteJson("/api/v1/admin/blog/categories/{$used->id}")->assertStatus(422);
        $this->deleteJson("/api/v1/admin/blog/categories/{$free->id}")->assertNoContent();
        $this->assertDatabaseMissing('blog_subscriptions', ['subject_type' => 'category', 'subject_id' => $free->id]);
        $this->deleteJson('/api/v1/admin/blog/categories/999999')->assertNotFound();
    }

    /** @test */
    public function tags_are_flat_have_unique_slugs_and_no_description(): void
    {
        $this->actingAsAdmin();

        $tag = $this->postJson('/api/v1/admin/blog/tags', ['name' => 'Gaming', 'description' => 'ignored'])
            ->assertCreated()->assertJsonPath('data.slug', 'gaming')->json('data');
        $this->assertArrayNotHasKey('description', $tag);
        $this->assertArrayNotHasKey('parent_id', $tag);

        $this->postJson('/api/v1/admin/blog/tags', ['name' => 'Other', 'slug' => 'gaming'])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->postJson('/api/v1/admin/blog/tags', [])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->patchJson("/api/v1/admin/blog/tags/{$tag['id']}", ['name' => 'Games', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'Games')->assertJsonPath('data.is_active', false);

        // Inactive tags are invisible publicly.
        $this->getJson('/api/v1/blog/tags/gaming')->assertNotFound();
        $this->getJson('/api/v1/blog/tags')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/blog/tags')->assertJsonCount(1, 'data');
    }

    /** @test */
    public function deleting_a_tag_detaches_it_from_posts_without_touching_them(): void
    {
        $tag = $this->makeTag();
        $post = $this->makePost(BlogPostStatus::Published, [], ['tags' => [$tag]]);
        $this->actingAsAdmin();

        $this->deleteJson("/api/v1/admin/blog/tags/{$tag->id}")->assertNoContent();

        $this->assertDatabaseMissing('blog_post_tag', ['blog_tag_id' => $tag->id]);
        $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertOk()->assertJsonCount(0, 'data.tags');
    }

    /** @test */
    public function post_requests_never_create_tags_implicitly(): void
    {
        $author = $this->makeAuthor();
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author, ['tag_ids' => ['brand-new-tag']]))
            ->assertStatus(422)->assertJsonValidationErrors('tag_ids.0');
        $this->assertDatabaseCount('blog_tags', 0);
    }

    /** @test */
    public function authors_link_to_identity_only_through_the_contract(): void
    {
        $user = User::factory()->create();
        $this->actingAsAdmin();

        $author = $this->postJson('/api/v1/admin/blog/authors', [
            'display_name' => 'سارا محمدی', 'user_id' => $user->id, 'job_title' => 'Editor', 'bio' => 'Writes guides',
        ])->assertCreated()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.slug', 'سارا-محمدی')
            ->json('data');

        $this->postJson('/api/v1/admin/blog/authors', ['display_name' => 'Ghost', 'user_id' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->postJson('/api/v1/admin/blog/authors', ['display_name' => 'Dup', 'user_id' => $user->id])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');

        // The public profile never exposes the Identity link.
        $public = $this->getJson('/api/v1/blog/authors/'.urlencode($author['slug']))->assertOk()->json('data');
        $this->assertArrayNotHasKey('user_id', $public);
        $this->assertSame('Writes guides', $public['bio']);
    }

    /** @test */
    public function a_credited_author_cannot_be_deleted_but_can_be_deactivated(): void
    {
        $credited = $this->makeAuthor();
        $post = $this->makePost(BlogPostStatus::Published, [], ['authors' => [$credited]]);
        $free = $this->makeAuthor();
        $this->actingAsAdmin();

        $this->deleteJson("/api/v1/admin/blog/authors/{$credited->id}")->assertStatus(422)->assertJsonValidationErrors('author');
        $this->deleteJson("/api/v1/admin/blog/authors/{$free->id}")->assertNoContent();

        $this->patchJson("/api/v1/admin/blog/authors/{$credited->id}", ['is_active' => false])->assertOk();
        $this->getJson("/api/v1/blog/authors/{$credited->slug}")->assertNotFound();
        $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertOk()->assertJsonCount(0, 'data.authors');
    }

    /** @test */
    public function author_and_category_media_must_exist(): void
    {
        $media = $this->makeMedia();
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/blog/authors', ['display_name' => 'A', 'media_id' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors('media_id');
        $this->postJson('/api/v1/admin/blog/authors', ['display_name' => 'B', 'media_id' => $media->id])
            ->assertCreated()->assertJsonPath('data.avatar.id', $media->id);
        $this->postJson('/api/v1/admin/blog/categories', ['name' => 'C', 'media_id' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors('media_id');
    }

    /** @test */
    public function taxonomy_permissions_are_granular(): void
    {
        $category = $this->makeCategory();

        $this->postJson('/api/v1/admin/blog/categories', ['name' => 'x'])->assertUnauthorized();

        $this->actingAsCustomer();
        $this->postJson('/api/v1/admin/blog/categories', ['name' => 'x'])->assertForbidden();
        $this->postJson('/api/v1/admin/blog/tags', ['name' => 'x'])->assertForbidden();
        $this->postJson('/api/v1/admin/blog/authors', ['display_name' => 'x'])->assertForbidden();
        $this->getJson('/api/v1/admin/blog/categories')->assertForbidden();

        $this->actingWithPermissions('blog.tag.manage');
        $this->postJson('/api/v1/admin/blog/tags', ['name' => 'ok'])->assertCreated();
        $this->postJson('/api/v1/admin/blog/categories', ['name' => 'no'])->assertForbidden();
        $this->getJson('/api/v1/admin/blog/tags')->assertOk();

        $this->actingWithPermissions('blog.category.manage');
        $this->patchJson("/api/v1/admin/blog/categories/{$category->id}", ['name' => 'Renamed'])->assertOk();
        $this->getJson('/api/v1/admin/blog/categories/tree')->assertOk();

        $this->actingWithPermissions('blog.author.manage');
        $this->postJson('/api/v1/admin/blog/authors', ['display_name' => 'Writer'])->assertCreated();
    }
}
