<?php

namespace Modules\Catalog\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Catalog\Application\Actions\CreateCategoryAction;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Infrastructure\Http\Requests\StoreCategoryRequest;
use Modules\Catalog\Infrastructure\Http\Resources\CategoryResource;

class CategoriesController extends Controller
{
    public function __construct(
        private readonly CreateCategoryAction    $createAction,
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

    public function indexRoots(): JsonResponse
    {
        $categories = $this->catalog->getActiveRootCategories();

        return response()->json(CategoryResource::collection($categories));
    }
}
