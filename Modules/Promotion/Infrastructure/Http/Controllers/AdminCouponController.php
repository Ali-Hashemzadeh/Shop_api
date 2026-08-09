<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Modules\Promotion\Application\Actions\SaveCouponAction;
use Modules\Promotion\Domain\Contracts\PromotionManagerInterface;
use Modules\Promotion\Infrastructure\Http\Requests\IndexPromotionRequest;
use Modules\Promotion\Infrastructure\Http\Requests\StoreCouponRequest;
use Modules\Promotion\Infrastructure\Http\Requests\UpdateCouponRequest;
use Modules\Promotion\Infrastructure\Http\Resources\CouponResource;

/**
 * Admin coupon CRUD.
 *
 * Reads require promotion.view-admin; every mutation requires the separate
 * promotion.coupon.manage, so code management can be delegated without granting
 * control over the underlying pricing rules.
 */
class AdminCouponController extends Controller
{
    public function __construct(
        private readonly PromotionManagerInterface $promotion,
        private readonly SaveCouponAction $save,
    ) {}

    public function index(IndexPromotionRequest $request): AnonymousResourceCollection
    {
        return CouponResource::collection(
            $this->promotion->getCoupons($request->filters(), $request->perPage())
        );
    }

    public function show(IndexPromotionRequest $request, int $coupon): JsonResponse
    {
        $dto = $this->promotion->findCoupon($coupon);

        if ($dto === null) {
            return response()->json(['message' => 'Coupon not found.'], 404);
        }

        return response()->json(new CouponResource($dto));
    }

    public function store(StoreCouponRequest $request): JsonResponse
    {
        return response()->json(
            new CouponResource($this->save->create($request->validated())),
            201
        );
    }

    public function update(UpdateCouponRequest $request, int $coupon): JsonResponse
    {
        return response()->json(
            new CouponResource($this->save->update($coupon, $request->validated()))
        );
    }

    public function destroy(int $coupon): JsonResponse
    {
        abort_unless(request()->user()?->can('promotion.coupon.manage'), 403);

        // Soft-delete keeps redemption history and the codes printed on historical
        // orders resolvable, and keeps the unique index holding the code so it can
        // never be reissued against different terms.
        $this->save->delete($coupon);

        return response()->json(null, 204);
    }
}
