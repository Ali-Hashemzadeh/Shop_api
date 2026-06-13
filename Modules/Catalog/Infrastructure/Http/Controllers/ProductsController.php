<?php

namespace Modules\Catalog\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Catalog\Application\Actions\CreateProductAction;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Infrastructure\Http\Requests\StoreProductRequest;
use Modules\Catalog\Infrastructure\Http\Resources\ProductResource;

class ProductsController extends Controller
{
    public function __construct(
        private readonly CreateProductAction     $createAction,
        private readonly CatalogManagerInterface $catalog,
    ) {}

    public function store(StoreProductRequest $request): JsonResponse
    {
        $dto = $this->createAction->handle(
            $request->safe()->except(['primary_image', 'gallery']),
            $request->file('primary_image'),
            $request->file('gallery') ?? [],
        );

        return response()->json(new ProductResource($dto), 201);
    }

    public function show(int $id): JsonResponse
    {
        $dto = $this->catalog->findProduct($id);

        if ($dto === null) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json(new ProductResource($dto));
    }

    public function showAdmin(int $id): JsonResponse
    {
        $dto = $this->catalog->findProductAdmin($id);

        if ($dto === null) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json(new ProductResource($dto));
    }

    public function showBySlug(string $slug): JsonResponse
    {
        $dto = $this->catalog->findProductBySlug($slug);

        if ($dto === null) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json(new ProductResource($dto));
    }

    public function indexByCategory(int $categoryId): JsonResponse
    {
        $products = $this->catalog->getProductsByCategory($categoryId);

        return response()->json(ProductResource::collection($products));
    }
}
