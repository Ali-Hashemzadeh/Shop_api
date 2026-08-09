<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Controllers;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Modules\Promotion\Domain\Contracts\PromotionManagerInterface;
use Modules\Promotion\Infrastructure\Http\Requests\IndexPromotionRequest;
use Modules\Promotion\Infrastructure\Http\Resources\CouponRedemptionResource;

/**
 * Coupon usage history. Read-only by design: redemption rows are financial
 * history, mutated only by the order lifecycle (reserve / redeem / release),
 * never by hand.
 */
class AdminRedemptionController extends Controller
{
    public function __construct(
        private readonly PromotionManagerInterface $promotion,
    ) {}

    public function index(IndexPromotionRequest $request): AnonymousResourceCollection
    {
        return CouponRedemptionResource::collection(
            $this->promotion->getRedemptions($request->filters(), $request->perPage())
        );
    }
}
