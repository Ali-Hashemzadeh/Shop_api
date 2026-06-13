<?php

namespace Modules\Catalog\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Modules\Catalog\Application\Actions\CreateProductAction;
use Modules\Catalog\Application\Actions\DeleteProductAction;
use Modules\Catalog\Application\Actions\UpdateProductAction;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Infrastructure\Http\Requests\IndexProductsRequest;
use Modules\Catalog\Infrastructure\Http\Requests\StoreProductRequest;
use Modules\Catalog\Infrastructure\Http\Requests\UpdateProductRequest;
use Modules\Catalog\Infrastructure\Http\Resources\ProductResource;

class ProductsController extends Controller
{
    public function __construct(
        private readonly CreateProductAction     $createAction,
        private readonly UpdateProductAction     $updateAction,
        private readonly DeleteProductAction     $deleteAction,
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

    public function update(UpdateProductRequest $request, int $id): JsonResponse
    {
        $dto = $this->updateAction->handle(
            $id,
            $request->safe()->except(['primary_image']),
            $request->file('primary_image'),
        );

        return response()->json(new ProductResource($dto));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->deleteAction->handle($id);

        return response()->json(null, 204);
    }

    public function indexByCategory(int $categoryId, IndexProductsRequest $request): AnonymousResourceCollection
    {
        $products = $this->catalog->getProductsByCategory(
            $categoryId,
            $request->integer('per_page', 15)
        );

        return ProductResource::collection($products);
    }
}
