<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Blog\Application\Actions\SetBlogPostBookmarkAction;
use Modules\Blog\Application\Actions\SetBlogPostLikeAction;
use Modules\Blog\Domain\Models\BlogPostStat;
use Modules\Blog\Infrastructure\Http\Controllers\Concerns\InteractsWithBlogViewer;
use Modules\Blog\Infrastructure\Http\Requests\IndexPublicBlogListRequest;
use Modules\Blog\Infrastructure\Http\Resources\BlogPostSummaryResource;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogPostQueries;

/**
 * Self-service reader engagement: likes and bookmarks (authentication only, no
 * permission — like the product wishlist, but Blog-owned). All toggles are
 * idempotent and caller-scoped; the post must be publicly visible.
 */
class BlogEngagementController extends Controller
{
    use InteractsWithBlogViewer;

    public function like(Request $request, string $slug, SetBlogPostLikeAction $action): JsonResponse
    {
        return $this->setLike($request, $slug, $action, true);
    }

    public function unlike(Request $request, string $slug, SetBlogPostLikeAction $action): JsonResponse
    {
        return $this->setLike($request, $slug, $action, false);
    }

    public function bookmark(Request $request, string $slug, SetBlogPostBookmarkAction $action): JsonResponse
    {
        $post = $this->publicPostOrFail($slug);
        $action->handle($post, (int) $request->user()->getAuthIdentifier(), true);

        return response()->json(['bookmarked' => true]);
    }

    public function unbookmark(Request $request, string $slug, SetBlogPostBookmarkAction $action): JsonResponse
    {
        $post = $this->publicPostOrFail($slug);
        $action->handle($post, (int) $request->user()->getAuthIdentifier(), false);

        return response()->json(['bookmarked' => false]);
    }

    /** The caller's own saved posts (still-public ones only), newest save first. */
    public function bookmarks(IndexPublicBlogListRequest $request, BlogPostQueries $posts): JsonResponse
    {
        return $this->paginated(BlogPostSummaryResource::class, $posts->paginateBookmarks(
            (int) $request->user()->getAuthIdentifier(),
            (int) $request->validated('per_page', 15),
        ));
    }

    private function setLike(Request $request, string $slug, SetBlogPostLikeAction $action, bool $liked): JsonResponse
    {
        $post = $this->publicPostOrFail($slug);
        $action->handle($post, (int) $request->user()->getAuthIdentifier(), $liked);

        return response()->json([
            'liked' => $liked,
            'likes_count' => (int) (BlogPostStat::query()->whereKey($post->id)->value('likes_count') ?? 0),
        ]);
    }
}
