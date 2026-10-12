<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Blog\Domain\Models\BlogCollection;
use Modules\Blog\Infrastructure\Http\Controllers\Concerns\InteractsWithBlogViewer;
use Modules\Blog\Infrastructure\Http\Requests\IndexPublicBlogListRequest;
use Modules\Blog\Infrastructure\Http\Resources\BlogCollectionResource;
use Modules\Blog\Infrastructure\Http\Resources\BlogPlacementResource;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogLookupQueries;

/**
 * Public editorial curation: active collections (inside their date window)
 * and keyed placements (active window, published posts only, deterministic
 * order).
 */
class BlogCurationController extends Controller
{
    use InteractsWithBlogViewer;

    public function __construct(
        private readonly BlogLookupQueries $lookups,
    ) {}

    public function collections(IndexPublicBlogListRequest $request): JsonResponse
    {
        return $this->paginated(BlogCollectionResource::class, $this->lookups->paginateCollections(
            ['search' => $request->validated('search')],
            (int) $request->validated('per_page', 15),
            public: true,
        ));
    }

    public function collection(Request $request, string $slug): JsonResponse
    {
        $collection = BlogCollection::query()->currentlyVisible()->where('slug', $slug)->firstOrFail();

        return response()->json([
            'data' => new BlogCollectionResource($this->lookups->collectionDto($collection, withPosts: true, viewerId: $this->viewerId($request))),
        ]);
    }

    public function placements(Request $request, string $key): JsonResponse
    {
        return response()->json([
            'data' => BlogPlacementResource::collection($this->lookups->publicPlacements($key, $this->viewerId($request))),
        ]);
    }
}
