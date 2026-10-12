<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use Illuminate\Support\Facades\URL;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostRevision;

/**
 * Revisions (append-only, restore-as-new), autosave, private editorial notes,
 * and signed time-limited previews.
 */
class BlogEditorialToolsTest extends BlogTestCase
{
    private function createPost(): array
    {
        $author = $this->makeAuthor();
        $category = $this->makeCategory();
        $this->actingAsAdmin();

        return $this->postJson('/api/v1/admin/blog/posts', $this->postPayload($author, [
            'title' => 'Version one',
            'content' => '<p>first body</p>',
            'category_ids' => [$category->id],
        ]))->assertCreated()->json('data');
    }

    /** @test */
    public function revisions_are_listed_newest_first_and_inspectable(): void
    {
        $post = $this->createPost();
        $this->patchJson("/api/v1/admin/blog/posts/{$post['id']}", ['title' => 'Version two', 'content' => '<p>second</p>', 'version' => 1])->assertOk();

        $list = $this->getJson("/api/v1/admin/blog/posts/{$post['id']}/revisions")->assertOk();
        $list->assertJsonCount(2, 'data')->assertJsonPath('data.0.revision_number', 2)->assertJsonPath('data.1.title', 'Version one');
        $this->assertArrayNotHasKey('content', $list->json('data.0'));

        $first = $list->json('data.1.id');
        $this->getJson("/api/v1/admin/blog/posts/{$post['id']}/revisions/{$first}")
            ->assertOk()
            ->assertJsonPath('data.content', '<p>first body</p>')
            ->assertJsonPath('data.taxonomy_snapshot.categories.0.is_primary', true)
            ->assertJsonPath('data.author_snapshot.0.role', 'author');

        // A revision of another post is not reachable through this post.
        $other = $this->makePost(BlogPostStatus::Draft);
        $this->getJson("/api/v1/admin/blog/posts/{$other->id}/revisions/{$first}")->assertNotFound();
    }

    /** @test */
    public function restoring_a_revision_creates_a_new_revision_and_never_rewrites_history(): void
    {
        $post = $this->createPost();
        $this->patchJson("/api/v1/admin/blog/posts/{$post['id']}", ['title' => 'Version two', 'content' => '<p>second</p>', 'version' => 1])->assertOk();
        $first = BlogPostRevision::query()->where('blog_post_id', $post['id'])->where('revision_number', 1)->first();
        $originalCreatedAt = $first->created_at;

        $this->postJson("/api/v1/admin/blog/posts/{$post['id']}/revisions/{$first->id}/restore", ['version' => 1])->assertStatus(409);

        $this->postJson("/api/v1/admin/blog/posts/{$post['id']}/revisions/{$first->id}/restore", ['version' => 2])
            ->assertOk()
            ->assertJsonPath('data.title', 'Version one')
            ->assertJsonPath('data.content', '<p>first body</p>')
            ->assertJsonPath('data.version', 3);

        $revisions = BlogPostRevision::query()->where('blog_post_id', $post['id'])->orderBy('revision_number')->get();
        $this->assertSame([1, 2, 3], $revisions->pluck('revision_number')->all());
        $this->assertSame('Version two', $revisions[1]->title, 'history is untouched');
        $this->assertSame('Version one', $revisions[2]->title);
        $this->assertTrue($originalCreatedAt->equalTo($revisions[0]->created_at));
        $this->assertDatabaseHas('blog_post_activity', ['blog_post_id' => $post['id'], 'action' => 'revision_restored']);
    }

    /** @test */
    public function revision_restore_requires_update_permission(): void
    {
        $post = $this->createPost();
        $revision = BlogPostRevision::query()->where('blog_post_id', $post['id'])->first();

        $this->actingWithPermissions('blog.view-admin');
        $this->getJson("/api/v1/admin/blog/posts/{$post['id']}/revisions")->assertOk();
        $this->postJson("/api/v1/admin/blog/posts/{$post['id']}/revisions/{$revision->id}/restore", ['version' => 1])->assertForbidden();

        $this->actingWithPermissions('blog.post.update-any');
        $this->postJson("/api/v1/admin/blog/posts/{$post['id']}/revisions/{$revision->id}/restore", ['version' => 1])->assertOk();
    }

    /** @test */
    public function autosave_is_replaceable_recoverable_and_never_an_official_revision(): void
    {
        $post = $this->createPost();

        $this->getJson("/api/v1/admin/blog/posts/{$post['id']}/autosave")->assertOk()->assertJsonPath('data', null);

        $this->putJson("/api/v1/admin/blog/posts/{$post['id']}/autosave", ['title' => 'typing…', 'content' => '<p>a</p>'])->assertOk();
        $this->putJson("/api/v1/admin/blog/posts/{$post['id']}/autosave", ['title' => 'typing more', 'content' => '<p>ab</p>'])->assertOk();

        $this->assertDatabaseCount('blog_post_autosaves', 1);
        $this->assertSame(1, BlogPostRevision::query()->where('blog_post_id', $post['id'])->count());
        $this->assertSame(1, (int) BlogPost::find($post['id'])->version);

        $this->getJson("/api/v1/admin/blog/posts/{$post['id']}/autosave")
            ->assertJsonPath('data.title', 'typing more')
            ->assertJsonPath('data.is_stale', false);

        // The manual save becomes official and clears the editor's autosave.
        $this->patchJson("/api/v1/admin/blog/posts/{$post['id']}", ['title' => 'typing more', 'version' => 1])->assertOk();
        $this->assertDatabaseCount('blog_post_autosaves', 0);
        $this->assertSame(2, BlogPostRevision::query()->where('blog_post_id', $post['id'])->count());
    }

    /** @test */
    public function editorial_notes_are_private_and_resolvable(): void
    {
        $post = $this->createPost();
        $admin = auth()->user();

        $note = $this->postJson("/api/v1/admin/blog/posts/{$post['id']}/notes", ['body' => 'Check the price claims'])
            ->assertCreated()->assertJsonPath('data.is_resolved', false)->json('data');
        $this->postJson("/api/v1/admin/blog/posts/{$post['id']}/notes", [])->assertStatus(422);

        $this->patchJson("/api/v1/admin/blog/posts/{$post['id']}/notes/{$note['id']}", ['resolved' => true])
            ->assertOk()->assertJsonPath('data.is_resolved', true);
        $this->patchJson("/api/v1/admin/blog/posts/{$post['id']}/notes/{$note['id']}", ['resolved' => false])
            ->assertOk()->assertJsonPath('data.is_resolved', false);

        $this->getJson("/api/v1/admin/blog/posts/{$post['id']}/notes")->assertOk()->assertJsonCount(1, 'data');

        // Another collaborator may resolve but not rewrite someone else's note.
        $this->actingWithPermissions('blog.post.update-any');
        $this->patchJson("/api/v1/admin/blog/posts/{$post['id']}/notes/{$note['id']}", ['body' => 'hijack'])
            ->assertStatus(422)->assertJsonValidationErrors('body');

        // Customers and the public never see them.
        $this->actingAsCustomer();
        $this->getJson("/api/v1/admin/blog/posts/{$post['id']}/notes")->assertForbidden();
        $this->postJson("/api/v1/admin/blog/posts/{$post['id']}/notes", ['body' => 'x'])->assertForbidden();

        $this->actingAsAdmin($admin);
        $this->postJson("/api/v1/admin/blog/posts/{$post['id']}/submit-review");
        $this->postJson("/api/v1/admin/blog/posts/{$post['id']}/approve");
        $slug = $this->postJson("/api/v1/admin/blog/posts/{$post['id']}/publish")->json('data.slug');
        $this->assertStringNotContainsString('Check the price claims', $this->getJson("/api/v1/blog/posts/{$slug}")->getContent());
    }

    /** @test */
    public function a_signed_preview_link_exposes_one_unpublished_post_until_it_expires(): void
    {
        $post = $this->createPost();

        $link = $this->postJson("/api/v1/admin/blog/posts/{$post['id']}/preview-link")
            ->assertCreated()->assertJsonStructure(['data' => ['url', 'expires_at']])->json('data.url');

        // Public detail stays 404; the signed link works without a token.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/blog/posts/{$post['slug']}")->assertNotFound();
        $this->getJson($link)
            ->assertOk()
            ->assertJsonPath('data.is_preview', true)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.title', 'Version one')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        // Tampering with the post id or using an unsigned URL is refused.
        $this->getJson(str_replace("/preview/{$post['id']}", '/preview/999', $link))->assertForbidden();
        $this->getJson("/api/v1/blog/preview/{$post['id']}")->assertForbidden();
        $this->getJson("/api/v1/blog/posts/{$post['slug']}?preview=true")->assertNotFound();

        // Expired after the configured TTL.
        $this->travel((int) config('blog.preview.ttl_minutes') + 1)->minutes();
        $this->getJson($link)->assertForbidden();
    }

    /** @test */
    public function a_preview_can_pin_a_specific_revision(): void
    {
        $post = $this->createPost();
        $this->patchJson("/api/v1/admin/blog/posts/{$post['id']}", ['title' => 'Version two', 'version' => 1])->assertOk();
        $first = BlogPostRevision::query()->where('blog_post_id', $post['id'])->where('revision_number', 1)->value('id');
        $foreign = BlogPostRevision::query()->create(['blog_post_id' => $this->makePost(BlogPostStatus::Draft)->id, 'revision_number' => 1, 'title' => 'x', 'slug' => 'x', 'status_snapshot' => 'draft']);

        $this->postJson("/api/v1/admin/blog/posts/{$post['id']}/preview-link", ['revision_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('revision_id');

        $url = $this->postJson("/api/v1/admin/blog/posts/{$post['id']}/preview-link", ['revision_id' => $first])->json('data.url');
        $this->getJson($url)->assertOk()->assertJsonPath('data.title', 'Version one');

        $this->assertTrue(URL::hasValidSignature(request()->create($url)));
    }

    /** @test */
    public function preview_links_require_staff_access(): void
    {
        $post = $this->makePost(BlogPostStatus::Draft);

        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/preview-link")->assertUnauthorized();
        $this->actingAsCustomer();
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/preview-link")->assertForbidden();
        $this->actingWithPermissions('blog.view-admin');
        $this->postJson("/api/v1/admin/blog/posts/{$post->id}/preview-link")->assertCreated();
    }
}
