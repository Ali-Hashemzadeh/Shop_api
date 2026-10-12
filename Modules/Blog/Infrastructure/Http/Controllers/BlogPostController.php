<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Blog\Application\Actions\RecordBlogPostViewAction;
use Modules\Blog\Application\Actions\RecordBlogProductClickAction;
use Modules\Blog\Application\Support\BlogEngagementGate;
use Modules\Blog\Infrastructure\Http\Controllers\Concerns\InteractsWithBlogViewer;
use Modules\Blog\Infrastructure\Http\Requests\IndexPublicBlogListRequest;
use Modules\Blog\Infrastructure\Http\Requests\IndexPublicBlogPostsRequest;
use Modules\Blog\Infrastructure\Http\Requests\RecordBlogProductClickRequest;
use Modules\Blog\Infrastructure\Http\Resources\BlogPostDetailResource;
use Modules\Blog\Infrastructure\Http\Resources\BlogPostSummaryResource;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogPostHydrator;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogPostQueries;

/**
 * Public article reads + engagement tracking. Published-only, always: every
 * lookup goes through BlogPostQueries, whose queries start from
 * BlogPost::publiclyVisible().
 */
class BlogPostController extends Controller
{
    use InteractsWithBlogViewer;

    public function __construct(
        private readonly BlogPostQueries $posts,
        private readonly BlogPostHydrator $hydrator,
    ) {}

    public function index(IndexPublicBlogPostsRequest $request): JsonResponse
    {
        return $this->paginated(BlogPostSummaryResource::class, $this->posts->paginatePublic(
            $request->filters(),
            (int) $request->validated('per_page', 15),
            $this->viewerId($request),
        ));
    }

    /**
     * Detail by slug, by exact public code, or by a retired slug — the last
     * returns the post with `redirect: {from, to, status: 301}` so the
     * storefront can redirect to the canonical slug.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $found = $this->posts->findPublic($slug);

        abort_if($found === null, 404);

        return response()->json([
            'data' => new BlogPostDetailResource($this->hydrator->detail(
                $found['post'],
                $this->viewerId($request),
                $found['redirected_from'],
            )),
        ]);
    }

    public function related(IndexPublicBlogListRequest $request, string $slug): JsonResponse
    {
        $post = $this->publicPostOrFail($slug);
        $limit = (int) $request->validated('limit', (int) config('blog.related.default_limit', 4));

        $related = $this->posts->related($post, $limit);

        return response()->json([
            'data' => BlogPostSummaryResource::collection($this->hydrator->summaries($related, $this->viewerId($request))),
        ]);
    }

    /** Count one view (deduplicated, bot-filtered). 202 either way. */
    public function recordView(Request $request, string $slug, RecordBlogPostViewAction $action): JsonResponse
    {
        $post = $this->publicPostOrFail($slug);
        $viewerId = $this->viewerId($request);

        $counted = $action->handle(
            $post,
            BlogEngagementGate::viewerKey($viewerId, $request->header('X-Session-Id'), $request->ip(), $request->userAgent()),
            $viewerId,
            $request->userAgent(),
        );

        return response()->json(['recorded' => $counted], 202);
    }

    /** Record a click on a product linked from this article. 422 if the product is not linked. */
    public function recordProductClick(RecordBlogProductClickRequest $request, string $slug, RecordBlogProductClickAction $action): JsonResponse
    {
        $post = $this->publicPostOrFail($slug);
        $productId = (int) $request->validated('product_id');

        if (! DB::table('blog_post_products')->where('blog_post_id', $post->id)->where('product_id', $productId)->exists()) {
            return response()->json([
                'message' => 'This product is not linked from the article.',
                'errors' => ['product_id' => ['This product is not linked from the article.']],
            ], 422);
        }

        $viewerId = $this->viewerId($request);
        $sessionId = $request->header('X-Session-Id');

        $counted = $action->handle(
            $post,
            $productId,
            BlogEngagementGate::viewerKey($viewerId, $sessionId, $request->ip(), $request->userAgent()),
            $viewerId,
            $sessionId !== null && $sessionId !== '' ? substr(hash('sha256', $sessionId), 0, 32) : null,
            $request->validated('placement'),
            $request->userAgent(),
        );

        return response()->json(['recorded' => $counted], 202);
    }
}
