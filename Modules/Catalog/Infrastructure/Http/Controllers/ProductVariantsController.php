<?php

namespace Modules\Catalog\Infrastructure\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Catalog\Application\Actions\CreateProductVariantAction;
use Modules\Catalog\Application\Actions\DeleteProductVariantAction;
use Modules\Catalog\Application\Actions\UpdateProductVariantAction;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Catalog\Infrastructure\Http\Requests\StoreProductVariantRequest;
use Modules\Catalog\Infrastructure\Http\Requests\UpdateProductVariantRequest;
use Modules\Catalog\Infrastructure\Http\Resources\ProductVariantResource;

class ProductVariantsController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CreateProductVariantAction $createAction,
        private readonly UpdateProductVariantAction $updateAction,
        private readonly DeleteProductVariantAction $deleteAction,
        private readonly CatalogManagerInterface $catalog,
    ) {}

    public function store(StoreProductVariantRequest $request, string $productUuid): JsonResponse
    {
        $product = Product::query()->where('uuid', $productUuid)->firstOrFail();

        $dto = $this->createAction->handle(
            $product->id,
            $request->safe()->except(['variant_image']),
            $request->file('variant_image'),
        );

        return response()->json(new ProductVariantResource($dto), 201);
    }

    public function show(int $variantId): JsonResponse
    {
        $dto = $this->catalog->findVariant($variantId);

        if ($dto === null) {
            return response()->json(['message' => 'Variant not found.'], 404);
        }

        return response()->json(new ProductVariantResource($dto));
    }

    public function showBySku(string $sku): JsonResponse
    {
        $dto = $this->catalog->findVariantBySku($sku);

        if ($dto === null) {
            return response()->json(['message' => 'Variant not found.'], 404);
        }

        return response()->json(new ProductVariantResource($dto));
    }

    public function update(UpdateProductVariantRequest $request, int $variantId): JsonResponse
    {
        $dto = $this->updateAction->handle(
            $variantId,
            $request->safe()->except(['variant_image']),
            $request->file('variant_image'),
        );

        return response()->json(new ProductVariantResource($dto));
    }

    public function destroy(int $variantId): JsonResponse
    {
        $this->authorize('delete', ProductVariant::class);

        $this->deleteAction->handle($variantId);

        return response()->json(null, 204);
    }
}
