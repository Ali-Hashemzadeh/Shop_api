<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\PublicCodeGenerator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Blog\Application\Actions\CreateBlogCommentAction;
use Modules\Blog\Application\Actions\DeleteBlogCommentAction;
use Modules\Blog\Application\Actions\ReactToBlogCommentAction;
use Modules\Blog\Application\Actions\RemoveBlogCommentReactionAction;
use Modules\Blog\Application\Actions\ReportBlogCommentAction;
use Modules\Blog\Application\Actions\UpdateBlogCommentAction;
use Modules\Blog\Application\Support\BlogCommentThreading;
use Modules\Blog\Domain\DTOs\BlogCommentReportDTO;
use Modules\Blog\Domain\Enums\BlogCommentReaction;
use Modules\Blog\Domain\Enums\BlogCommentReportReason;
use Modules\Blog\Domain\Models\BlogComment;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Infrastructure\Http\Controllers\Concerns\InteractsWithBlogViewer;
use Modules\Blog\Infrastructure\Http\Requests\IndexPublicBlogListRequest;
use Modules\Blog\Infrastructure\Http\Requests\ReactToBlogCommentRequest;
use Modules\Blog\Infrastructure\Http\Requests\ReportBlogCommentRequest;
use Modules\Blog\Infrastructure\Http\Requests\StoreBlogCommentRequest;
use Modules\Blog\Infrastructure\Http\Requests\UpdateBlogCommentRequest;
use Modules\Blog\Infrastructure\Http\Resources\BlogCommentReportResource;
use Modules\Blog\Infrastructure\Http\Resources\BlogCommentResource;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogCommentQueries;

/**
 * Blog discussion. Reads are public and approved-only. Writes need a token;
 * creating needs `blog.comment.create`; edit/delete are owner-only (policy 403).
 * A target that is not publicly visible (unknown code, pending/rejected comment,
 * unpublished post) is a 404 — indistinguishable from nonexistent.
 */
class BlogCommentController extends Controller
{
    use AuthorizesRequests;
    use InteractsWithBlogViewer;

    public function __construct(
        private readonly BlogCommentQueries $comments,
    ) {}

    public function index(IndexPublicBlogListRequest $request, string $slug): JsonResponse
    {
        $post = $this->publicPostOrFail($slug);

        return $this->paginated(BlogCommentResource::class, $this->comments->paginatePublicThreads(
            $post,
            (int) $request->validated('per_page', 15),
            (string) $request->validated('sort', 'newest'),
            $this->viewerId($request),
        ));
    }

    public function store(StoreBlogCommentRequest $request, string $slug, CreateBlogCommentAction $action): JsonResponse
    {
        $post = $this->publicPostOrFail($slug);

        if ($error = $this->commentsClosed($post)) {
            return $error;
        }

        $comment = $action->handle(
            $post,
            (int) $request->user()->getAuthIdentifier(),
            (string) $request->validated('body'),
            array_map('intval', (array) $request->validated('media_ids', [])),
        );

        return response()->json(['data' => new BlogCommentResource($this->comments->single($comment, $this->viewerId($request)))], 201);
    }

    public function reply(StoreBlogCommentRequest $request, string $publicCode, CreateBlogCommentAction $action): JsonResponse
    {
        $parent = $this->comments->findPublic($publicCode);
        abort_if($parent === null, 404);

        $post = $parent->post;
        if ($error = $this->commentsClosed($post)) {
            return $error;
        }

        if (! BlogCommentThreading::acceptsReplies($parent)) {
            return response()->json([
                'message' => 'This conversation has reached its maximum reply depth.',
                'errors' => ['parent' => ['This conversation has reached its maximum reply depth.']],
            ], 422);
        }

        $comment = $action->handle(
            $post,
            (int) $request->user()->getAuthIdentifier(),
            (string) $request->validated('body'),
            array_map('intval', (array) $request->validated('media_ids', [])),
            $parent,
        );

        return response()->json(['data' => new BlogCommentResource($this->comments->single($comment, $this->viewerId($request)))], 201);
    }

    public function update(UpdateBlogCommentRequest $request, UpdateBlogCommentAction $action): JsonResponse
    {
        $comment = $action->handle(
            $request->comment(),
            (string) $request->validated('body'),
            $request->has('media_ids') ? array_map('intval', (array) $request->validated('media_ids', [])) : null,
        );

        return response()->json(['data' => new BlogCommentResource($this->comments->single($comment, $this->viewerId($request)))]);
    }

    public function destroy(Request $request, string $publicCode, DeleteBlogCommentAction $action): Response
    {
        $comment = BlogComment::query()->where('public_code', PublicCodeGenerator::normalize($publicCode))->firstOrFail();
        $this->authorize('delete', $comment);

        $action->handle($comment);

        return response()->noContent();
    }

    public function report(ReportBlogCommentRequest $request, string $publicCode, ReportBlogCommentAction $action): JsonResponse
    {
        $comment = $this->comments->findPublic($publicCode);
        abort_if($comment === null, 404);

        $userId = (int) $request->user()->getAuthIdentifier();
        if ((int) $comment->user_id === $userId) {
            return response()->json([
                'message' => 'You cannot report your own comment.',
                'errors' => ['comment' => ['You cannot report your own comment.']],
            ], 422);
        }

        $result = $action->handle(
            $comment,
            $userId,
            BlogCommentReportReason::from((string) $request->validated('reason')),
            $request->validated('details'),
        );

        return response()->json([
            'data' => new BlogCommentReportResource(BlogCommentReportDTO::fromModel($result['report'])),
            'already_reported' => ! $result['created'],
        ], $result['created'] ? 201 : 200);
    }

    public function react(ReactToBlogCommentRequest $request, string $publicCode, ReactToBlogCommentAction $action): JsonResponse
    {
        $comment = $this->comments->findPublic($publicCode);
        abort_if($comment === null, 404);

        $action->handle($comment, (int) $request->user()->getAuthIdentifier(), BlogCommentReaction::from((string) $request->validated('reaction')));

        return response()->json(['data' => new BlogCommentResource($this->comments->single($comment, $this->viewerId($request)))]);
    }

    public function unreact(Request $request, string $publicCode, RemoveBlogCommentReactionAction $action): JsonResponse
    {
        $comment = $this->comments->findPublic($publicCode);
        abort_if($comment === null, 404);

        $action->handle($comment, (int) $request->user()->getAuthIdentifier());

        return response()->json(['data' => new BlogCommentResource($this->comments->single($comment, $this->viewerId($request)))]);
    }

    private function commentsClosed(BlogPost $post): ?JsonResponse
    {
        if ($post->allow_comments) {
            return null;
        }

        return response()->json([
            'message' => 'Comments are closed for this article.',
            'errors' => ['post' => ['Comments are closed for this article.']],
        ], 422);
    }
}
