<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Modules\Promotion\Application\Actions\SaveDiscountAction;
use Modules\Promotion\Domain\Contracts\PromotionManagerInterface;
use Modules\Promotion\Infrastructure\Http\Requests\IndexPromotionRequest;
use Modules\Promotion\Infrastructure\Http\Requests\StoreDiscountRequest;
use Modules\Promotion\Infrastructure\Http\Requests\UpdateDiscountRequest;
use Modules\Promotion\Infrastructure\Http\Resources\DiscountResource;

/**
 * Admin discount CRUD. Every route's authorization is enforced by its Form
 * Request, so unauthorized callers get 403 before validation.
 */
class AdminDiscountController extends Controller
{
    public function __construct(
        private readonly PromotionManagerInterface $promotion,
        private readonly SaveDiscountAction $save,
    ) {}

    public function index(IndexPromotionRequest $request): AnonymousResourceCollection
    {
        return DiscountResource::collection(
            $this->promotion->getDiscounts($request->filters(), $request->perPage())
        );
    }

    public function show(IndexPromotionRequest $request, int $discount): JsonResponse
    {
        $dto = $this->promotion->findDiscount($discount);

        if ($dto === null) {
            return response()->json(['message' => 'Discount not found.'], 404);
        }

        return response()->json(new DiscountResource($dto));
    }

    public function store(StoreDiscountRequest $request): JsonResponse
    {
        return response()->json(
            new DiscountResource($this->save->create($request->validated())),
            201
        );
    }

    public function update(UpdateDiscountRequest $request, int $discount): JsonResponse
    {
        return response()->json(
            new DiscountResource($this->save->update($discount, $request->validated()))
        );
    }

    public function destroy(int $discount): JsonResponse
    {
        // Authorization for the one route without a Form Request.
        abort_unless(request()->user()?->can('promotion.delete'), 403);

        // Soft-delete: redemptions and historical order snapshots are untouched.
        $this->save->delete($discount);

        return response()->json(null, 204);
    }
}
