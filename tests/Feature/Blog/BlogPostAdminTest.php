<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostActivity;
use Modules\Blog\Domain\Models\BlogPostRevision;
use Modules\Blog\Domain\Models\BlogPostSlugHistory;

/**
 * Admin post CRUD: public code, slugs, optimistic locking, revisions, audit
 * trail, soft delete/restore, filtering, and the auth matrix.
 */
class BlogPostAdminTest extends BlogTestCase
{
    /** @test */
    public function an_editor_creates_a_draft_with_a_server_minted_public_code(): void
    {
        $author = $this->makeAuthor();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.primary_author_id', $author->id);

        $code = $response->json('data.public_code');
        $this->assertTrue(PublicCodeGenerator::matches($code, PublicCodeEntity::BlogPost));
        $this->assertStringStartsWith(PublicCodeGenerator::prefix(PublicCodeEntity::BlogPost), $code);
        $this->assertDatabaseHas('blog_posts', ['public_code' => $code, 'status' => 'draft']);
        $this->assertSame(1, BlogPostRevision::query()->count());
        $this->assertDatabaseHas('blog_post_activity', ['action' => 'created']);
    }

    /** @test */
    public function public_codes_are_unique_and_never_accepted_from_the_client(): void
    {
        $author = $this->makeAuthor();
        $this->actingAsAdmin();

        $first = $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author, [
            'public_code' => 'bdb-AAAAAA',
            'status' => 'published',
        ]))->assertCreated();
        $second = $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author))->assertCreated();

        $this->assertNotSame('bdb-AAAAAA', $first->json('data.public_code'));
        $this->assertSame('draft', $first->json('data.status'));
        $this->assertNotSame($first->json('data.public_code'), $second->json('data.public_code'));

        // Updates cannot change it either.
        $id = $first->json('data.id');
        $this->patchJson("/api/v1/admin/blog/posts/{$id}", ['version' => 1, 'public_code' => 'bdb-BBBBBB'])->assertOk();
        $this->assertSame($first->json('data.public_code'), BlogPost::find($id)->public_code);
    }

    /** @test */
    public function slugs_are_generated_from_persian_titles_and_deduplicated(): void
    {
        $author = $this->makeAuthor();
        $this->actingAsAdmin();

        $a = $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author, ['title' => 'راهنمای خرید گوشی']))->assertCreated();
        $b = $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author, ['title' => 'راهنمای خرید گوشی']))->assertCreated();

        $this->assertSame('راهنمای-خرید-گوشی', $a->json('data.slug'));
        $this->assertSame('راهنمای-خرید-گوشی-2', $b->json('data.slug'));
    }

    /** @test */
    public function an_explicit_duplicate_or_code_shaped_slug_is_rejected(): void
    {
        $author = $this->makeAuthor();
        $existing = $this->makePost(BlogPostStatus::Draft, ['slug' => 'taken-slug']);
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author, ['slug' => 'taken-slug']))
            ->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author, ['slug' => 'bdb-k92xmq']))
            ->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author, ['slug' => 'Bad Slug!']))
            ->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->assertSame(1, BlogPost::query()->count());
        $this->assertNotNull($existing);
    }

    /** @test */
    public function create_validates_required_fields_and_cross_module_references(): void
    {
        $author = $this->makeAuthor();
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/blog/posts', [])
            ->assertStatus(422)->assertJsonValidationErrors(['title', 'authors']);

        $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author, [
            'featured_media_id' => 999999,
            'products' => [['product_id' => 999999]],
            'category_ids' => [999999],
            'tag_ids' => [999999],
        ]))->assertStatus(422)->assertJsonValidationErrors(['category_ids.0', 'tag_ids.0']);

        $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author, [
            'featured_media_id' => 999999,
            'products' => [['product_id' => 999999]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['featured_media_id', 'products.0.product_id']);
    }

    /** @test */
    public function an_inactive_author_cannot_be_credited_and_an_author_role_is_required(): void
    {
        $inactive = $this->makeAuthor(['is_active' => false]);
        $active = $this->makeAuthor();
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($inactive))
            ->assertStatus(422)->assertJsonValidationErrors('authors.0.author_id');

        $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($active, [
            'authors' => [['author_id' => $active->id, 'role' => 'editor']],
        ]))->assertStatus(422)->assertJsonValidationErrors('authors');
    }

    /** @test */
    public function co_authors_are_ordered_and_primary_author_stays_consistent(): void
    {
        $editor = $this->makeAuthor(['display_name' => 'Editor']);
        $writer = $this->makeAuthor(['display_name' => 'Writer']);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($writer, [
            'authors' => [
                ['author_id' => $editor->id, 'role' => 'editor'],
                ['author_id' => $writer->id, 'role' => 'author'],
            ],
        ]))->assertCreated();

        $response->assertJsonPath('data.authors.0.id', $editor->id)
            ->assertJsonPath('data.authors.0.role', 'editor')
            ->assertJsonPath('data.authors.1.id', $writer->id)
            ->assertJsonPath('data.primary_author_id', $writer->id);
    }

    /** @test */
    public function update_requires_the_current_version_and_returns_409_on_a_stale_one(): void
    {
        $post = $this->makePost(BlogPostStatus::Draft);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", ['title' => 'No version'])
            ->assertStatus(422)->assertJsonValidationErrors('version');

        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", ['title' => 'Edit A', 'version' => 1])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.title', 'Edit A');

        // A second editor still holding version 1 must not silently overwrite.
        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", ['title' => 'Edit B', 'version' => 1])
            ->assertStatus(409)
            ->assertJsonPath('current_version', 2)
            ->assertJsonPath('submitted_version', 1);

        $this->assertSame('Edit A', $post->fresh()->title);
    }

    /** @test */
    public function a_meaningful_save_records_a_revision_and_activity_but_a_non_content_change_does_not_revision(): void
    {
        $post = $this->makePost(BlogPostStatus::Draft);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", ['content' => '<p>New body</p>', 'version' => 1])->assertOk();
        $this->assertSame(1, BlogPostRevision::query()->where('blog_post_id', $post->id)->count());

        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", ['allow_comments' => false, 'version' => 2])->assertOk();
        $this->assertSame(1, BlogPostRevision::query()->where('blog_post_id', $post->id)->count());

        $this->assertSame(2, BlogPostActivity::query()->where('blog_post_id', $post->id)->where('action', 'updated')->count());
    }

    /** @test */
    public function featuring_and_unfeaturing_are_audited(): void
    {
        $post = $this->makePost(BlogPostStatus::Draft);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", ['is_featured' => true, 'version' => 1])->assertOk();
        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", ['is_featured' => false, 'version' => 2])->assertOk();

        $this->assertDatabaseHas('blog_post_activity', ['blog_post_id' => $post->id, 'action' => 'featured']);
        $this->assertDatabaseHas('blog_post_activity', ['blog_post_id' => $post->id, 'action' => 'unfeatured']);
    }

    /** @test */
    public function changing_a_published_slug_keeps_the_old_one_in_history_but_a_title_change_never_moves_it(): void
    {
        $post = $this->makePost(BlogPostStatus::Published, ['slug' => 'old-slug']);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", ['title' => 'Brand new title', 'version' => 1])
            ->assertOk()->assertJsonPath('data.slug', 'old-slug');

        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", ['slug' => 'new-slug', 'version' => 2])
            ->assertOk()->assertJsonPath('data.slug', 'new-slug');

        $this->assertDatabaseHas('blog_post_slug_history', ['blog_post_id' => $post->id, 'slug' => 'old-slug']);
        $this->assertDatabaseHas('blog_post_activity', ['blog_post_id' => $post->id, 'action' => 'slug_changed']);

        // Reclaiming the old slug removes it from history (no redirect loop).
        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", ['slug' => 'old-slug', 'version' => 3])->assertOk();
        $this->assertDatabaseMissing('blog_post_slug_history', ['slug' => 'old-slug']);
        $this->assertDatabaseHas('blog_post_slug_history', ['slug' => 'new-slug']);
    }

    /** @test */
    public function a_retired_slug_is_reserved_for_its_owner(): void
    {
        $owner = $this->makePost(BlogPostStatus::Published, ['slug' => 'current-slug']);
        BlogPostSlugHistory::query()->create(['blog_post_id' => $owner->id, 'slug' => 'retired-slug']);
        $other = $this->makePost(BlogPostStatus::Draft);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/blog/posts/{$other->id}", ['slug' => 'retired-slug', 'version' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    /** @test */
    public function taxonomy_is_synced_with_exactly_one_primary_category(): void
    {
        $post = $this->makePost(BlogPostStatus::Draft);
        $a = $this->makeCategory();
        $b = $this->makeCategory();
        $tag1 = $this->makeTag();
        $tag2 = $this->makeTag();
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", [
            'version' => 1,
            'category_ids' => [$a->id, $b->id],
            'primary_category_id' => $b->id,
            'tag_ids' => [$tag1->id, $tag2->id],
        ])->assertOk();

        $this->assertDatabaseHas('blog_post_category', ['blog_post_id' => $post->id, 'blog_category_id' => $b->id, 'is_primary' => true]);
        $this->assertDatabaseHas('blog_post_category', ['blog_post_id' => $post->id, 'blog_category_id' => $a->id, 'is_primary' => false]);

        // Without an explicit primary the first category becomes primary.
        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", ['version' => 2, 'category_ids' => [$a->id], 'tag_ids' => [$tag2->id]])->assertOk();
        $this->assertSame(1, $post->categories()->wherePivot('is_primary', true)->count());
        $this->assertSame([$tag2->id], $post->tags()->pluck('blog_tags.id')->all());

        // A primary that is not among the categories is invalid.
        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", ['version' => 3, 'primary_category_id' => $b->id])
            ->assertStatus(422)->assertJsonValidationErrors('primary_category_id');

        // Empty list removes them all.
        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", ['version' => 3, 'category_ids' => [], 'tag_ids' => []])->assertOk();
        $this->assertSame(0, $post->categories()->count());
        $this->assertSame(0, $post->tags()->count());
    }

    /** @test */
    public function media_links_store_blog_specific_alt_text_without_a_media_fk(): void
    {
        $author = $this->makeAuthor();
        $hero = $this->makeMedia();
        $inline = $this->makeMedia(path: 'blog/inline.jpg');
        $og = $this->makeMedia(path: 'blog/og.jpg');
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author, [
            'featured_media_id' => $hero->id,
            'og_media_id' => $og->id,
            'media' => [
                ['media_id' => $inline->id, 'role' => 'inline', 'alt_text' => 'Phone on desk', 'caption' => 'Our pick'],
                ['media_id' => $hero->id, 'role' => 'gallery', 'alt_text' => 'Different meaning here'],
            ],
        ]))->assertCreated();

        $response->assertJsonPath('data.featured_media.id', $hero->id)
            ->assertJsonPath('data.og_media.id', $og->id)
            ->assertJsonPath('data.media.0.alt_text', 'Phone on desk')
            ->assertJsonPath('data.media.1.role', 'gallery');

        $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author, [
            'media' => [['media_id' => 999999, 'role' => 'inline']],
        ]))->assertStatus(422)->assertJsonValidationErrors('media.0.media_id');
    }

    /** @test */
    public function delete_is_soft_and_keeps_history_and_restore_brings_it_back(): void
    {
        $post = $this->makePost(BlogPostStatus::Published);
        BlogPostRevision::query()->create(['blog_post_id' => $post->id, 'revision_number' => 1, 'title' => 't', 'slug' => 's', 'status_snapshot' => 'published']);
        $this->actingAsAdmin();

        $this->deleteJson("/api/v1/admin/blog/posts/{$post->id}")->assertNoContent();
        $this->assertSoftDeleted('blog_posts', ['id' => $post->id]);
        $this->assertSame(1, BlogPostRevision::query()->where('blog_post_id', $post->id)->count());
        $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertNotFound();

        // Deleted posts are not editable.
        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", ['title' => 'x', 'version' => 1])->assertNotFound();

        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/restore")->assertOk()->assertJsonPath('data.deleted_at', null);
        $this->getJson("/api/v1/blog/posts/{$post->slug}")->assertOk();
        $this->assertDatabaseHas('blog_post_activity', ['blog_post_id' => $post->id, 'action' => 'deleted']);
        $this->assertDatabaseHas('blog_post_activity', ['blog_post_id' => $post->id, 'action' => 'restored']);

        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/restore")->assertStatus(422);
    }

    /** @test */
    public function the_admin_index_filters_by_status_taxonomy_author_search_and_trash(): void
    {
        $category = $this->makeCategory();
        $tag = $this->makeTag();
        $author = $this->makeAuthor();
        $match = $this->makePost(BlogPostStatus::Draft, ['title' => 'Needle article'], ['categories' => [$category], 'tags' => [$tag], 'authors' => [$author]]);
        $this->makePost(BlogPostStatus::Published, ['title' => 'Other']);
        $trashed = $this->makePost(BlogPostStatus::Draft, ['title' => 'Gone']);
        $trashed->delete();
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/blog/posts?status=draft')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/blog/posts?category_id={$category->id}")->assertJsonPath('data.0.id', $match->id);
        $this->getJson("/api/v1/admin/blog/posts?tag_id={$tag->id}")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/blog/posts?author_id={$author->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/blog/posts?search=Needle')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/blog/posts?search={$match->public_code}")->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/blog/posts?trashed=only')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $trashed->id);
        $this->getJson('/api/v1/admin/blog/posts')->assertJsonCount(2, 'data')->assertJsonStructure(['data', 'links', 'meta']);

        // List rows never carry article HTML.
        $this->assertArrayNotHasKey('content', $this->getJson('/api/v1/admin/blog/posts')->json('data.0'));
    }

    /** @test */
    public function the_admin_auth_matrix_is_401_403_and_exact_permission_success(): void
    {
        $author = $this->makeAuthor();
        $post = $this->makePost(BlogPostStatus::Draft);

        $this->getJson('/api/v1/admin/blog/posts')->assertUnauthorized();
        $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author))->assertUnauthorized();

        $this->actingAsCustomer();
        $this->getJson('/api/v1/admin/blog/posts')->assertForbidden();
        $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author))->assertForbidden();
        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", ['version' => 1])->assertForbidden();
        $this->deleteJson("/api/v1/admin/blog/posts/{$post->id}")->assertForbidden();

        // No role, just the exact permission.
        $this->actingWithPermissions('blog.post.create');
        $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author))->assertCreated();

        $this->actingWithPermissions('blog.view-admin');
        $this->getJson('/api/v1/admin/blog/posts')->assertOk();
        $this->getJson("/api/v1/admin/blog/posts/{$post->id}")->assertOk()->assertJsonPath('data.content', $post->content);

        $this->actingWithPermissions('blog.post.update-any');
        $this->patchJson("/api/v1/admin/blog/posts/{$post->id}", ['title' => 'ok', 'version' => 1])->assertOk();

        $this->actingWithPermissions('blog.post.delete');
        $this->deleteJson("/api/v1/admin/blog/posts/{$post->id}")->assertNoContent();

        $this->actingWithPermissions('blog.post.restore');
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/restore")->assertOk();

        $this->actingAsAdmin();
        $this->patchJson('/api/v1/admin/blog/posts/999999', ['version' => 1])->assertNotFound();
    }

    /** @test */
    public function update_own_lets_an_author_edit_only_their_own_draft(): void
    {
        $writer = $this->actingWithPermissions('blog.post.update-own');
        $profile = $this->makeAuthor(['user_id' => $writer->id]);
        $own = $this->makePost(BlogPostStatus::Draft, [], ['authors' => [$profile]]);
        $created = $this->makePost(BlogPostStatus::Draft, ['created_by_user_id' => $writer->id]);
        $foreign = $this->makePost(BlogPostStatus::Draft);
        $ownInReview = $this->makePost(BlogPostStatus::InReview, [], ['authors' => [$profile]]);

        $this->patchJson("/api/v1/admin/blog/posts/{$own->id}", ['title' => 'mine', 'version' => 1])->assertOk();
        $this->patchJson("/api/v1/admin/blog/posts/{$created->id}", ['title' => 'mine too', 'version' => 1])->assertOk();
        $this->patchJson("/api/v1/admin/blog/posts/{$foreign->id}", ['title' => 'nope', 'version' => 1])->assertForbidden();
        $this->patchJson("/api/v1/admin/blog/posts/{$ownInReview->id}", ['title' => 'locked', 'version' => 1])->assertForbidden();
    }

    /** @test */
    public function the_activity_trail_is_listable_by_admins(): void
    {
        $author = $this->makeAuthor();
        $this->actingAsAdmin();
        $id = $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author))->json('data.id');

        $this->getJson("/api/v1/admin/blog/posts/{$id}/activity")
            ->assertOk()
            ->assertJsonPath('data.0.action', 'created')
            ->assertJsonStructure(['data', 'links', 'meta']);
    }
}
