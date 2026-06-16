<?php

namespace Modules\Catalog\Infrastructure\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Modules\Catalog\Application\Actions\CreateCategoryAction;
use Modules\Catalog\Application\Actions\DeleteCategoryAction;
use Modules\Catalog\Application\Actions\UpdateCategoryAction;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\Models\Category;
use Modules\Catalog\Infrastructure\Http\Requests\IndexCategoriesRequest;
use Modules\Catalog\Infrastructure\Http\Requests\StoreCategoryRequest;
use Modules\Catalog\Infrastructure\Http\Requests\UpdateCategoryRequest;
use Modules\Catalog\Infrastructure\Http\Resources\CategoryResource;

class CategoriesController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CreateCategoryAction $createAction,
        private readonly UpdateCategoryAction $updateAction,
        private readonly DeleteCategoryAction $deleteAction,
        private readonly CatalogManagerInterface $catalog,
    ) {}

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $dto = $this->createAction->handle(
            $request->safe()->except(['image']),
            $request->file('image'),
        );

        return response()->json(new CategoryResource($dto), 201);
    }

    public function show(int $id): JsonResponse
    {
        $dto = $this->catalog->findCategory($id);

        if ($dto === null) {
            return response()->json(['message' => 'Category not found.'], 404);
        }

        return response()->json(new CategoryResource($dto));
    }

    public function update(UpdateCategoryRequest $request, int $id): JsonResponse
    {
        $dto = $this->updateAction->handle(
            $id,
            $request->safe()->except(['image']),
            $request->file('image'),
        );

        return response()->json(new CategoryResource($dto));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorize('delete', Category::class);

        $this->deleteAction->handle($id);

        return response()->json(null, 204);
    }

    public function indexRoots(IndexCategoriesRequest $request): AnonymousResourceCollection
    {
        $categories = $this->catalog->getActiveRootCategories(
            $request->integer('per_page', 15)
        );

        return CategoryResource::collection($categories);
    }
}
