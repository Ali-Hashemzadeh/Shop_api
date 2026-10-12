<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Blog\Application\Actions\DeleteBlogCollectionAction;
use Modules\Blog\Application\Actions\DeleteBlogPlacementAction;
use Modules\Blog\Application\Actions\SaveBlogCollectionAction;
use Modules\Blog\Application\Actions\SaveBlogPlacementAction;
use Modules\Blog\Domain\Models\BlogCollection;
use Modules\Blog\Domain\Models\BlogPlacement;
use Modules\Blog\Infrastructure\Http\Controllers\Concerns\InteractsWithBlogViewer;
use Modules\Blog\Infrastructure\Http\Requests\IndexAdminBlogLookupRequest;
use Modules\Blog\Infrastructure\Http\Requests\ManageBlogCollectionsRequest;
use Modules\Blog\Infrastructure\Http\Requests\ManageBlogPlacementsRequest;
use Modules\Blog\Infrastructure\Http\Requests\SaveBlogCollectionRequest;
use Modules\Blog\Infrastructure\Http\Requests\SaveBlogPlacementRequest;
use Modules\Blog\Infrastructure\Http\Resources\BlogCollectionResource;
use Modules\Blog\Infrastructure\Http\Resources\BlogPlacementResource;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogLookupQueries;

/** Collections and keyed placements management. */
class AdminBlogCurationController extends Controller
{
    use InteractsWithBlogViewer;

    public function __construct(
        private readonly BlogLookupQueries $lookups,
    ) {}

    public function collections(IndexAdminBlogLookupRequest $request): JsonResponse
    {
        return $this->paginated(BlogCollectionResource::class, $this->lookups->paginateCollections(
            $request->safe()->only(['search', 'is_active']),
            (int) $request->validated('per_page', 15),
            public: false,
        ));
    }

    public function storeCollection(SaveBlogCollectionRequest $request, SaveBlogCollectionAction $action): JsonResponse
    {
        $collection = $action->handle(null, $request->validated());

        return response()->json(['data' => new BlogCollectionResource($this->lookups->collectionDto($collection, withPostIds: true))], 201);
    }

    public function showCollection(IndexAdminBlogLookupRequest $request, int $collection): JsonResponse
    {
        $model = BlogCollection::query()->findOrFail($collection);

        return response()->json(['data' => new BlogCollectionResource($this->lookups->collectionDto($model, withPostIds: true))]);
    }

    public function updateCollection(SaveBlogCollectionRequest $request, int $collection, SaveBlogCollectionAction $action): JsonResponse
    {
        $model = $action->handle(BlogCollection::query()->findOrFail($collection), $request->validated());

        return response()->json(['data' => new BlogCollectionResource($this->lookups->collectionDto($model, withPostIds: true))]);
    }

    public function destroyCollection(ManageBlogCollectionsRequest $request, int $collection, DeleteBlogCollectionAction $action): Response
    {
        $action->handle(BlogCollection::query()->findOrFail($collection));

        return response()->noContent();
    }

    public function placements(IndexAdminBlogLookupRequest $request): JsonResponse
    {
        return $this->paginated(BlogPlacementResource::class, $this->lookups->paginatePlacementsAdmin(
            $request->safe()->only(['placement_key']),
            (int) $request->validated('per_page', 15),
        ));
    }

    public function storePlacement(SaveBlogPlacementRequest $request, SaveBlogPlacementAction $action): JsonResponse
    {
        return response()->json(['data' => new BlogPlacementResource($this->lookups->placementDto($action->handle(null, $request->validated())))], 201);
    }

    public function updatePlacement(SaveBlogPlacementRequest $request, int $placement, SaveBlogPlacementAction $action): JsonResponse
    {
        $model = $action->handle(BlogPlacement::query()->findOrFail($placement), $request->validated());

        return response()->json(['data' => new BlogPlacementResource($this->lookups->placementDto($model))]);
    }

    public function destroyPlacement(ManageBlogPlacementsRequest $request, int $placement, DeleteBlogPlacementAction $action): Response
    {
        $action->handle(BlogPlacement::query()->findOrFail($placement));

        return response()->noContent();
    }
}
