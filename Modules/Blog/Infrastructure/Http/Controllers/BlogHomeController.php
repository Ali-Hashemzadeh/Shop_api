<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Infrastructure\Http\Controllers\Concerns\InteractsWithBlogViewer;
use Modules\Blog\Infrastructure\Http\Resources\BlogCategoryResource;
use Modules\Blog\Infrastructure\Http\Resources\BlogPlacementResource;
use Modules\Blog\Infrastructure\Http\Resources\BlogPostSummaryResource;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogLookupQueries;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogPostHydrator;

/**
 * GET /api/v1/blog — one call for the Blog landing page: featured posts,
 * latest posts, the configured placement slots, and the category tree.
 */
class BlogHomeController extends Controller
{
    use InteractsWithBlogViewer;

    public function __construct(
        private readonly BlogPostHydrator $hydrator,
        private readonly BlogLookupQueries $lookups,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $viewerId = $this->viewerId($request);

        $featured = BlogPost::query()->publiclyVisible()->select(BlogPost::listColumns())->where('blog_posts.is_featured', true)
            ->with(BlogPostHydrator::SUMMARY_RELATIONS)
            ->orderByDesc('published_at')->orderByDesc('id')
            ->limit((int) config('blog.home.featured_limit', 6))
            ->get();

        $latest = BlogPost::query()->publiclyVisible()->select(BlogPost::listColumns())
            ->with(BlogPostHydrator::SUMMARY_RELATIONS)
            ->orderByDesc('published_at')->orderByDesc('id')
            ->limit((int) config('blog.home.latest_limit', 10))
            ->get();

        $placements = [];
        foreach ((array) config('blog.home.placement_keys', []) as $key) {
            $placements[$key] = BlogPlacementResource::collection($this->lookups->publicPlacements((string) $key, $viewerId));
        }

        return response()->json([
            'data' => [
                'featured' => BlogPostSummaryResource::collection($this->hydrator->summaries($featured, $viewerId)),
                'latest' => BlogPostSummaryResource::collection($this->hydrator->summaries($latest, $viewerId)),
                'placements' => $placements,
                'categories' => BlogCategoryResource::collection($this->lookups->categoryTree(activeOnly: true)),
            ],
        ]);
    }
}
