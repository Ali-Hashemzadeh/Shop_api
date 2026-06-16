<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Catalog\Application\Actions\AddProductImageAction;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Infrastructure\Http\Requests\StoreProductImageRequest;
use Modules\Catalog\Infrastructure\Http\Resources\ProductImageResource;

class ProductGalleryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AddProductImageAction $addAction,
        private readonly CatalogManagerInterface $catalog,
    ) {}

    public function store(StoreProductImageRequest $request, int $productId): JsonResponse
    {
        Product::query()->findOrFail($productId);

        $dto = $this->addAction->handle(
            $productId,
            $request->safe()->all(),
            $request->file('image'),
        );

        return response()->json(new ProductImageResource($dto), 201);
    }

    public function destroy(int $productId, int $imageId): JsonResponse
    {
        $this->authorize('update', Product::class);

        $this->catalog->removeProductImage($imageId);

        return response()->json(null, 204);
    }
}
