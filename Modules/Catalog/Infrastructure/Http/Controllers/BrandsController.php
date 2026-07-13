<?php

namespace Modules\Catalog\Infrastructure\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Modules\Catalog\Application\Actions\CreateBrandAction;
use Modules\Catalog\Application\Actions\DeleteBrandAction;
use Modules\Catalog\Application\Actions\UpdateBrandAction;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\Models\Brand;
use Modules\Catalog\Infrastructure\Http\Requests\IndexBrandsRequest;
use Modules\Catalog\Infrastructure\Http\Requests\StoreBrandRequest;
use Modules\Catalog\Infrastructure\Http\Requests\UpdateBrandRequest;
use Modules\Catalog\Infrastructure\Http\Resources\BrandResource;

class BrandsController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CreateBrandAction $createAction,
        private readonly UpdateBrandAction $updateAction,
        private readonly DeleteBrandAction $deleteAction,
        private readonly CatalogManagerInterface $catalog,
    ) {}

    public function store(StoreBrandRequest $request): JsonResponse
    {
        $dto = $this->createAction->handle(
            $request->safe()->except(['image']),
            $request->file('image'),
        );

        return response()->json(new BrandResource($dto), 201);
    }

    public function show(int $id): JsonResponse
    {
        $dto = $this->catalog->findBrand($id);

        if ($dto === null) {
            return response()->json(['message' => 'Brand not found.'], 404);
        }

        return response()->json(new BrandResource($dto));
    }

    public function update(UpdateBrandRequest $request, int $id): JsonResponse
    {
        $dto = $this->updateAction->handle(
            $id,
            $request->safe()->except(['image']),
            $request->file('image'),
        );

        return response()->json(new BrandResource($dto));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorize('delete', Brand::class);

        $this->deleteAction->handle($id);

        return response()->json(null, 204);
    }

    public function index(IndexBrandsRequest $request): AnonymousResourceCollection
    {
        $filters = array_filter([
            'is_active' => true,
            'search' => $request->string('search')->trim()->toString() ?: null,
        ], fn ($v) => $v !== null);

        return BrandResource::collection(
            $this->catalog->getBrands($filters, $request->integer('per_page', 15))
        );
    }
}
