<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Blog\Application\Actions\ApproveBlogPostAction;
use Modules\Blog\Application\Actions\ArchiveBlogPostAction;
use Modules\Blog\Application\Actions\PublishBlogPostAction;
use Modules\Blog\Application\Actions\ReturnBlogPostToDraftAction;
use Modules\Blog\Application\Actions\ScheduleBlogPostAction;
use Modules\Blog\Application\Actions\SubmitBlogPostForReviewAction;
use Modules\Blog\Application\Actions\UnpublishBlogPostAction;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Infrastructure\Http\Requests\ApproveBlogPostRequest;
use Modules\Blog\Infrastructure\Http\Requests\ArchiveBlogPostRequest;
use Modules\Blog\Infrastructure\Http\Requests\PublishBlogPostRequest;
use Modules\Blog\Infrastructure\Http\Requests\ReturnBlogPostToDraftRequest;
use Modules\Blog\Infrastructure\Http\Requests\ScheduleBlogPostRequest;
use Modules\Blog\Infrastructure\Http\Requests\SubmitBlogPostForReviewRequest;
use Modules\Blog\Infrastructure\Http\Resources\AdminBlogPostResource;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogPostHydrator;

/**
 * Editorial workflow endpoints — one per business action, no generic
 * "set status". Illegal moves are 422 on `status` (the transition map lives in
 * BlogPostStatus); each permission is checked by BlogPostPolicy.
 */
class AdminBlogWorkflowController extends Controller
{
    public function __construct(
        private readonly BlogPostHydrator $hydrator,
    ) {}

    public function submitReview(SubmitBlogPostForReviewRequest $request, SubmitBlogPostForReviewAction $action): JsonResponse
    {
        return $this->respond($action->handle($request->blogPost(), $this->actor($request)));
    }

    public function returnToDraft(ReturnBlogPostToDraftRequest $request, ReturnBlogPostToDraftAction $action): JsonResponse
    {
        return $this->respond($action->handle($request->blogPost(), $this->actor($request), $request->validated('reason')));
    }

    public function approve(ApproveBlogPostRequest $request, ApproveBlogPostAction $action): JsonResponse
    {
        return $this->respond($action->handle($request->blogPost(), $this->actor($request)));
    }

    public function schedule(ScheduleBlogPostRequest $request, ScheduleBlogPostAction $action): JsonResponse
    {
        return $this->respond($action->handle(
            $request->blogPost(),
            Carbon::parse((string) $request->validated('published_at')),
            $this->actor($request),
            $request->filled('unpublish_at') ? Carbon::parse((string) $request->validated('unpublish_at')) : null,
        ));
    }

    /** Idempotent: publishing a live post returns it unchanged. */
    public function publish(PublishBlogPostRequest $request, PublishBlogPostAction $action): JsonResponse
    {
        return $this->respond($action->handle($request->blogPost(), $this->actor($request)));
    }

    public function unpublish(PublishBlogPostRequest $request, UnpublishBlogPostAction $action): JsonResponse
    {
        return $this->respond($action->handle($request->blogPost(), $this->actor($request)));
    }

    public function archive(ArchiveBlogPostRequest $request, ArchiveBlogPostAction $action): JsonResponse
    {
        return $this->respond($action->handle($request->blogPost(), $this->actor($request)));
    }

    private function actor(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }

    private function respond(BlogPost $post): JsonResponse
    {
        return response()->json(['data' => new AdminBlogPostResource($this->hydrator->adminDetail($post->fresh()))]);
    }
}
