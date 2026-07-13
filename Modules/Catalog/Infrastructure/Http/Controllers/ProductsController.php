<?php

namespace Modules\Catalog\Infrastructure\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Modules\Catalog\Application\Actions\CreateProductAction;
use Modules\Catalog\Application\Actions\DeleteProductAction;
use Modules\Catalog\Application\Actions\UpdateProductAction;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Infrastructure\Http\Requests\IndexAdminProductsRequest;
use Modules\Catalog\Infrastructure\Http\Requests\IndexProductsRequest;
use Modules\Catalog\Infrastructure\Http\Requests\StoreProductRequest;
use Modules\Catalog\Infrastructure\Http\Requests\UpdateProductRequest;
use Modules\Catalog\Infrastructure\Http\Resources\ProductResource;

class ProductsController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CreateProductAction $createAction,
        private readonly UpdateProductAction $updateAction,
        private readonly DeleteProductAction $deleteAction,
        private readonly CatalogManagerInterface $catalog,
    ) {}

    public function store(StoreProductRequest $request): JsonResponse
    {
        $dto = $this->createAction->handle($request->validated());

        return response()->json(new ProductResource($dto), 201);
    }

    public function show(string $uuid): JsonResponse
    {
        $dto = $this->catalog->findProduct($uuid);

        if ($dto === null) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json(new ProductResource($dto));
    }

    public function showAdmin(string $uuid): JsonResponse
    {
        $this->authorize('viewAdmin', Product::class);

        $dto = $this->catalog->findProductAdmin($uuid);

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

    public function update(UpdateProductRequest $request, string $uuid): JsonResponse
    {
        $dto = $this->updateAction->handle($uuid, $request->validated());

        return response()->json(new ProductResource($dto));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->authorize('delete', Product::class);

        $this->deleteAction->handle($uuid);

        return response()->json(null, 204);
    }

    public function index(IndexProductsRequest $request): AnonymousResourceCollection
    {
        $filters = array_filter([
            'category_id' => $request->integer('category_id') ?: null,
            'brand_id' => $request->integer('brand_id') ?: null,
            'min_price' => $request->has('min_price') ? $request->integer('min_price') : null,
            'max_price' => $request->has('max_price') ? $request->integer('max_price') : null,
            'search' => $request->string('search')->trim()->toString() ?: null,
            'sort' => $request->string('sort')->trim()->toString() ?: null,
        ], fn ($v) => $v !== null);

        return ProductResource::collection(
            $this->catalog->getProducts($filters, $request->integer('per_page', 15))
        );
    }

    public function indexAdmin(IndexAdminProductsRequest $request): AnonymousResourceCollection
    {
        // Authorization (catalog.product.view-admin) is enforced in the Form Request,
        // so unauthorized users get 403 before validation runs.
        $filters = array_filter([
            'status' => $request->string('status')->trim()->toString() ?: null,
            'category_id' => $request->integer('category_id') ?: null,
            'brand_id' => $request->integer('brand_id') ?: null,
            'min_price' => $request->has('min_price') ? $request->integer('min_price') : null,
            'max_price' => $request->has('max_price') ? $request->integer('max_price') : null,
            'search' => $request->string('search')->trim()->toString() ?: null,
            'sort' => $request->string('sort')->trim()->toString() ?: null,
        ], fn ($v) => $v !== null);

        return ProductResource::collection(
            $this->catalog->getProductsAdmin($filters, $request->integer('per_page', 15))
        );
    }

    public function indexByCategory(int $categoryId, IndexProductsRequest $request): AnonymousResourceCollection
    {
        $filters = array_filter([
            'min_price' => $request->has('min_price') ? $request->integer('min_price') : null,
            'max_price' => $request->has('max_price') ? $request->integer('max_price') : null,
            'search' => $request->string('search')->trim()->toString() ?: null,
            'sort' => $request->string('sort')->trim()->toString() ?: null,
        ], fn ($v) => $v !== null);

        return ProductResource::collection(
            $this->catalog->getProductsByCategory($categoryId, $filters, $request->integer('per_page', 15))
        );
    }
}
