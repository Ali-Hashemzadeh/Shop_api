<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Blog\Domain\Enums\BlogCommentStatus;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Events\BlogCommentApprovedEvent;
use Modules\Blog\Domain\Events\BlogCommentCreatedEvent;
use Modules\Blog\Domain\Events\BlogCommentRepliedEvent;
use Modules\Blog\Domain\Models\BlogComment;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostStat;
use Modules\Identity\Domain\Models\User;

/**
 * The Blog discussion system (not the Review module): many comments per
 * reader, threaded replies with a depth limit, moderation, approved-only public
 * reads, edit re-moderation, soft-delete placeholders, reports, reactions, and
 * owned-media attachments.
 */
class BlogCommentTest extends BlogTestCase
{
    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();
        $this->post = $this->makePost(BlogPostStatus::Published, ['slug' => 'discussed']);
    }

    private function reader(string $name = 'Reza', string $lastName = 'Karimi'): User
    {
        $user = User::factory()->create(['name' => $name, 'last_name' => $lastName]);
        $user->assignRole('customer');
        Sanctum::actingAs($user);

        return $user;
    }

    private function comment(?User $user = null, BlogCommentStatus $status = BlogCommentStatus::Approved, ?BlogComment $parent = null, array $attributes = []): BlogComment
    {
        return BlogComment::createWithPublicCode([
            'blog_post_id' => $this->post->id,
            'user_id' => ($user ?? User::factory()->create())->id,
            'parent_id' => $parent?->id,
            'body' => 'A comment',
            'status' => $status->value,
            'approved_at' => $status === BlogCommentStatus::Approved ? now() : null,
            ...$attributes,
        ]);
    }

    /** @test */
    public function a_reader_can_post_many_comments_which_start_pending_and_stay_private(): void
    {
        Event::fake([BlogCommentCreatedEvent::class]);
        $this->reader();

        $first = $this->postJson('/api/v1/blog/posts/discussed/comments', ['body' => 'First thought'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_mine', true)
            ->json('data');
        $this->postJson('/api/v1/blog/posts/discussed/comments', ['body' => 'Second thought'])->assertCreated();

        $this->assertTrue(PublicCodeGenerator::matches($first['id'], PublicCodeEntity::BlogComment));
        $this->assertSame(2, BlogComment::query()->count());
        $this->getJson('/api/v1/blog/posts/discussed/comments')->assertOk()->assertJsonCount(0, 'data');
        Event::assertDispatchedTimes(BlogCommentCreatedEvent::class, 2);
    }

    /** @test */
    public function approved_comments_are_public_with_author_name_but_no_identity_data(): void
    {
        $author = User::factory()->create(['name' => 'Mina', 'last_name' => 'Rad']);
        $this->comment($author, BlogCommentStatus::Approved, attributes: ['body' => 'Visible']);
        $this->comment($author, BlogCommentStatus::Pending, attributes: ['body' => 'Pending']);
        $this->comment($author, BlogCommentStatus::Rejected, attributes: ['body' => 'Rejected']);
        $this->comment($author, BlogCommentStatus::Spam, attributes: ['body' => 'Spam']);

        $response = $this->getJson('/api/v1/blog/posts/discussed/comments')->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.body', 'Visible')->assertJsonPath('data.0.author.name', 'Mina Rad');

        $json = $response->getContent();
        $this->assertStringNotContainsString('user_id', $json);
        $this->assertStringNotContainsString($author->phone, $json);
        $this->assertStringNotContainsString((string) $author->email, $json);
        $this->assertArrayNotHasKey('status', $response->json('data.0'));
    }

    /** @test */
    public function comments_on_unpublished_or_closed_posts_are_refused(): void
    {
        $draft = $this->makePost(BlogPostStatus::Draft, ['slug' => 'secret-draft']);
        $closed = $this->makePost(BlogPostStatus::Published, ['slug' => 'closed', 'allow_comments' => false]);
        $this->reader();

        $this->postJson('/api/v1/blog/posts/secret-draft/comments', ['body' => 'x'])->assertNotFound();
        $this->postJson('/api/v1/blog/posts/closed/comments', ['body' => 'x'])->assertStatus(422)->assertJsonValidationErrors('post');
        $this->postJson('/api/v1/blog/posts/discussed/comments', ['body' => ''])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->assertNotNull($draft);
        $this->assertNotNull($closed);
    }

    /** @test */
    public function replies_attach_to_approved_comments_up_to_the_max_depth(): void
    {
        $root = $this->comment();
        $pending = $this->comment(status: BlogCommentStatus::Pending);
        $this->reader();

        $level2 = $this->postJson("/api/v1/blog/comments/{$root->public_code}/replies", ['body' => 'reply'])
            ->assertCreated()->assertJsonPath('data.parent_id', $root->public_code)->json('data');

        $this->postJson("/api/v1/blog/comments/{$pending->public_code}/replies", ['body' => 'nope'])->assertNotFound();

        // Approve level 2, reply (level 3), approve it, then level 4 is refused.
        $level2Model = BlogComment::query()->where('public_code', $level2['id'])->first();
        $level2Model->update(['status' => 'approved', 'approved_at' => now()]);
        $level3 = $this->postJson("/api/v1/blog/comments/{$level2['id']}/replies", ['body' => 'deeper'])->assertCreated()->json('data');
        BlogComment::query()->where('public_code', $level3['id'])->update(['status' => 'approved', 'approved_at' => now()]);

        $this->postJson("/api/v1/blog/comments/{$level3['id']}/replies", ['body' => 'too deep'])
            ->assertStatus(422)->assertJsonValidationErrors('parent');

        $thread = $this->getJson('/api/v1/blog/posts/discussed/comments')->assertOk()->json('data.0');
        $this->assertSame($root->public_code, $thread['id']);
        $this->assertSame($level2['id'], $thread['replies'][0]['id']);
        $this->assertSame($level3['id'], $thread['replies'][0]['replies'][0]['id']);
    }

    /** @test */
    public function editing_an_approved_comment_sends_it_back_to_moderation(): void
    {
        $user = $this->reader();
        $comment = $this->comment($user);
        BlogPostStat::query()->whereKey($this->post->id)->update(['comments_count' => 1]);

        $this->patchJson("/api/v1/blog/comments/{$comment->public_code}", ['body' => 'Edited text'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_edited', true);

        $this->getJson('/api/v1/blog/posts/discussed/comments')->assertJsonCount(0, 'data');
        $this->assertSame(0, BlogPostStat::query()->whereKey($this->post->id)->value('comments_count'));
    }

    /** @test */
    public function only_the_author_may_edit_and_the_author_or_a_moderator_may_delete(): void
    {
        $owner = User::factory()->create();
        $comment = $this->comment($owner);

        $this->patchJson("/api/v1/blog/comments/{$comment->public_code}", ['body' => 'x'])->assertUnauthorized();

        $this->reader();
        $this->patchJson("/api/v1/blog/comments/{$comment->public_code}", ['body' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/blog/comments/{$comment->public_code}")->assertForbidden();
        $this->patchJson('/api/v1/blog/comments/'.PublicCodeGenerator::prefix(PublicCodeEntity::BlogComment).'ZZZZZZ', ['body' => 'x'])->assertNotFound();

        $owner->assignRole('customer');
        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/blog/comments/{$comment->public_code}", ['body' => 'mine'])->assertOk();

        $this->actingWithPermissions('blog.comment.delete');
        $this->deleteJson("/api/v1/blog/comments/{$comment->public_code}")->assertNoContent();
        $this->assertSoftDeleted('blog_comments', ['id' => $comment->id]);
    }

    /** @test */
    public function a_deleted_parent_stays_as_a_placeholder_while_visible_replies_hang_below_it(): void
    {
        $parent = $this->comment(attributes: ['body' => 'Parent text']);
        $reply = $this->comment(parent: $parent, attributes: ['body' => 'Reply text']);
        $lonely = $this->comment(attributes: ['body' => 'Lonely']);

        $parent->delete();
        $lonely->delete();

        $data = $this->getJson('/api/v1/blog/posts/discussed/comments')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertTrue($data['is_deleted']);
        $this->assertNull($data['body']);
        $this->assertNull($data['author']);
        $this->assertSame($reply->public_code, $data['replies'][0]['id']);
        $this->assertSame('Reply text', $data['replies'][0]['body']);
    }

    /** @test */
    public function moderators_approve_reject_spam_and_restore_with_counters_and_events(): void
    {
        Event::fake([BlogCommentApprovedEvent::class, BlogCommentRepliedEvent::class]);
        $parentAuthor = User::factory()->create();
        $parent = $this->comment($parentAuthor);
        $reply = $this->comment(status: BlogCommentStatus::Pending, parent: $parent);
        $this->actingWithPermissions('blog.comment.moderate', 'blog.comment.view-admin');

        $this->postJson("/api/v1/admin/blog/comments/{$reply->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/admin/blog/comments/{$reply->id}/approve")->assertOk();
        Event::assertDispatchedTimes(BlogCommentApprovedEvent::class, 1);
        Event::assertDispatched(BlogCommentRepliedEvent::class, fn ($e) => $e->parentAuthorUserId === $parentAuthor->id && $e->replyCommentId === $reply->id);
        $this->assertSame(2, BlogPostStat::query()->whereKey($this->post->id)->value('comments_count'));

        $this->postJson("/api/v1/admin/blog/comments/{$reply->id}/spam")->assertOk()->assertJsonPath('data.status', 'spam');
        $this->postJson("/api/v1/admin/blog/comments/{$reply->id}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(1, BlogPostStat::query()->whereKey($this->post->id)->value('comments_count'));

        // Re-approval does not re-announce.
        $this->postJson("/api/v1/admin/blog/comments/{$reply->id}/approve")->assertOk();
        Event::assertDispatchedTimes(BlogCommentApprovedEvent::class, 1);

        $parent->delete();
        $this->postJson("/api/v1/admin/blog/comments/{$parent->id}/restore")->assertOk()->assertJsonPath('data.is_deleted', false);
        $this->postJson("/api/v1/admin/blog/comments/{$parent->id}/restore")->assertStatus(422);
    }

    /** @test */
    public function the_admin_queue_filters_by_status_post_user_reported_and_dates(): void
    {
        $user = User::factory()->create();
        $this->comment($user, BlogCommentStatus::Pending);
        $reported = $this->comment();
        $reported->reports()->create(['reported_by_user_id' => 1, 'reason' => 'spam', 'status' => 'open']);
        $this->actingWithPermissions('blog.comment.view-admin');

        $this->getJson('/api/v1/admin/blog/comments?status=pending')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/blog/comments?user_id={$user->id}")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/blog/comments?post_id={$this->post->id}")->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/blog/comments?reported=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.open_reports_count', 1);
        $this->getJson('/api/v1/admin/blog/comments?sort=most_reported')->assertJsonPath('data.0.id', $reported->id);
        $this->getJson('/api/v1/admin/blog/comments?date_from='.now()->addDay()->toDateString())->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/admin/blog/comments/{$reported->id}")->assertOk()->assertJsonPath('data.user_id', $reported->user_id);
    }

    /** @test */
    public function moderation_permissions_are_enforced(): void
    {
        $comment = $this->comment(status: BlogCommentStatus::Pending);

        $this->getJson('/api/v1/admin/blog/comments')->assertUnauthorized();
        $this->actingAsCustomer();
        $this->getJson('/api/v1/admin/blog/comments')->assertForbidden();
        $this->postJson("/api/v1/admin/blog/comments/{$comment->id}/approve")->assertForbidden();
        $this->deleteJson("/api/v1/admin/blog/comments/{$comment->id}")->assertForbidden();

        $this->actingWithPermissions('blog.comment.view-admin');
        $this->postJson("/api/v1/admin/blog/comments/{$comment->id}/approve")->assertForbidden();

        $this->actingWithPermissions('blog.comment.delete');
        $this->deleteJson("/api/v1/admin/blog/comments/{$comment->id}")->assertNoContent();
    }

    /** @test */
    public function reports_are_idempotent_while_open_and_closable_by_moderators(): void
    {
        $owner = User::factory()->create();
        $comment = $this->comment($owner);
        $this->reader();

        $this->postJson("/api/v1/blog/comments/{$comment->public_code}/report", ['reason' => 'nonsense'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $first = $this->postJson("/api/v1/blog/comments/{$comment->public_code}/report", ['reason' => 'spam', 'details' => 'ads'])
            ->assertCreated()->assertJsonPath('already_reported', false)->json('data.id');
        $this->postJson("/api/v1/blog/comments/{$comment->public_code}/report", ['reason' => 'abuse'])
            ->assertOk()->assertJsonPath('already_reported', true)->assertJsonPath('data.id', $first);
        $this->assertDatabaseCount('blog_comment_reports', 1);

        // Reporting your own comment is pointless.
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/blog/comments/{$comment->public_code}/report", ['reason' => 'spam'])->assertStatus(422);

        $this->actingWithPermissions('blog.comment.moderate');
        $this->getJson('/api/v1/admin/blog/comment-reports?status=open')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reason', 'spam');
        $this->postJson("/api/v1/admin/blog/comment-reports/{$first}/action", ['comment_status' => 'spam'])
            ->assertOk()->assertJsonPath('data.status', 'actioned')->assertJsonPath('data.comment_status', 'spam');
        $this->assertSame(BlogCommentStatus::Spam, $comment->fresh()->status);

        $second = $comment->reports()->create(['reported_by_user_id' => 99, 'reason' => 'other', 'status' => 'open']);
        $this->postJson("/api/v1/admin/blog/comment-reports/{$second->id}/dismiss")->assertOk()->assertJsonPath('data.status', 'dismissed');
        $third = $comment->reports()->create(['reported_by_user_id' => 98, 'reason' => 'other', 'status' => 'open']);
        $this->postJson("/api/v1/admin/blog/comment-reports/{$third->id}/review")->assertOk()->assertJsonPath('data.status', 'reviewed');

        $this->actingAsCustomer();
        $this->getJson('/api/v1/admin/blog/comment-reports')->assertForbidden();
    }

    /** @test */
    public function reactions_are_one_per_reader_and_idempotent(): void
    {
        $comment = $this->comment();
        $this->reader();

        $this->postJson("/api/v1/blog/comments/{$comment->public_code}/reactions", ['reaction' => 'like'])
            ->assertOk()->assertJsonPath('data.reactions.like', 1)->assertJsonPath('data.my_reaction', 'like');
        $this->postJson("/api/v1/blog/comments/{$comment->public_code}/reactions", ['reaction' => 'like'])
            ->assertOk()->assertJsonPath('data.reactions.like', 1);
        $this->postJson("/api/v1/blog/comments/{$comment->public_code}/reactions", ['reaction' => 'helpful'])
            ->assertOk()->assertJsonPath('data.reactions.like', 0)->assertJsonPath('data.reactions.helpful', 1);
        $this->postJson("/api/v1/blog/comments/{$comment->public_code}/reactions", ['reaction' => 'love'])->assertStatus(422);

        // Codes are case-normalized like every other public code.
        $this->postJson('/api/v1/blog/comments/'.strtolower($comment->public_code).'/reactions', ['reaction' => 'helpful'])
            ->assertOk()->assertJsonPath('data.id', $comment->public_code);

        $this->deleteJson("/api/v1/blog/comments/{$comment->public_code}/reactions")->assertOk()->assertJsonPath('data.my_reaction', null);
        $this->deleteJson("/api/v1/blog/comments/{$comment->public_code}/reactions")->assertOk();
        $this->assertDatabaseCount('blog_comment_reactions', 0);

        $pending = $this->comment(status: BlogCommentStatus::Pending);
        $this->postJson("/api/v1/blog/comments/{$pending->public_code}/reactions", ['reaction' => 'like'])->assertNotFound();
    }

    /** @test */
    public function attachments_must_be_media_the_commenter_uploaded(): void
    {
        $user = $this->reader();
        $own = $this->makeMedia($user->id);
        $foreign = $this->makeMedia(User::factory()->create()->id);

        $this->postJson('/api/v1/blog/posts/discussed/comments', ['body' => 'pic', 'media_ids' => [$foreign->id]])
            ->assertStatus(422)->assertJsonValidationErrors('media_ids');
        $this->postJson('/api/v1/blog/posts/discussed/comments', ['body' => 'pic', 'media_ids' => [999999]])
            ->assertStatus(422)->assertJsonValidationErrors('media_ids');

        $code = $this->postJson('/api/v1/blog/posts/discussed/comments', ['body' => 'pic', 'media_ids' => [$own->id]])
            ->assertCreated()->assertJsonPath('data.attachments.0.id', $own->id)->json('data.id');
        $this->assertDatabaseHas('blog_comment_media', ['media_id' => $own->id]);

        $this->patchJson("/api/v1/blog/comments/{$code}", ['body' => 'pic', 'media_ids' => [$foreign->id]])
            ->assertStatus(422)->assertJsonValidationErrors('media_ids');
    }

    /** @test */
    public function writing_comments_requires_authentication_and_the_comment_permission(): void
    {
        $comment = $this->comment();

        $this->postJson('/api/v1/blog/posts/discussed/comments', ['body' => 'x'])->assertUnauthorized();
        $this->postJson("/api/v1/blog/comments/{$comment->public_code}/replies", ['body' => 'x'])->assertUnauthorized();
        $this->postJson("/api/v1/blog/comments/{$comment->public_code}/report", ['reason' => 'spam'])->assertUnauthorized();
        $this->postJson("/api/v1/blog/comments/{$comment->public_code}/reactions", ['reaction' => 'like'])->assertUnauthorized();

        $this->actingWithPermissions();
        $this->postJson('/api/v1/blog/posts/discussed/comments', ['body' => 'x'])->assertForbidden();

        $this->actingWithPermissions('blog.comment.create');
        $this->postJson('/api/v1/blog/posts/discussed/comments', ['body' => 'x'])->assertCreated();
    }

    /** @test */
    public function the_comment_writer_limiter_returns_429(): void
    {
        config()->set('blog.comments.rate_limit_per_minute', 2);
        $this->reader();

        $this->postJson('/api/v1/blog/posts/discussed/comments', ['body' => '1'])->assertCreated();
        $this->postJson('/api/v1/blog/posts/discussed/comments', ['body' => '2'])->assertCreated();
        $this->postJson('/api/v1/blog/posts/discussed/comments', ['body' => '3'])->assertStatus(429)->assertHeader('Retry-After');
    }
}
