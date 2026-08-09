<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Infrastructure\Http\Requests\IndexProductsRequest;
use Modules\Catalog\Infrastructure\Http\Resources\ProductResource;
use Modules\Catalog\Infrastructure\Http\Resources\PublicCampaignResource;
use Modules\Promotion\Domain\Contracts\PromotionManagerInterface;

/**
 * Storefront campaign endpoints.
 *
 * These live in Catalog rather than Promotion on purpose. Resolving "which
 * products are in this campaign?" needs the product tables, the category tree, and
 * Catalog's own visibility rules — if Promotion answered it, Promotion would have
 * to query Catalog, and Catalog already queries Promotion for pricing. Instead
 * Promotion publishes campaign metadata plus raw target ids, and Catalog does the
 * resolution with its own SQL.
 */
class CampaignsController extends Controller
{
    public function __construct(
        private readonly CatalogManagerInterface $catalog,
        private readonly PromotionManagerInterface $promotion,
    ) {}

    /**
     * Publicly visible campaigns. `?landing=true` narrows to the ones flagged for
     * the landing page.
     */
    public function index(): AnonymousResourceCollection
    {
        $landingOnly = request()->boolean('landing');

        return PublicCampaignResource::collection(
            $this->promotion->getActiveCampaigns($landingOnly)
        );
    }

    public function show(string $slug): JsonResponse
    {
        $campaign = $this->promotion->findActiveCampaignBySlug($slug);

        if ($campaign === null) {
            return response()->json(['message' => 'Campaign not found.'], 404);
        }

        return response()->json(new PublicCampaignResource($campaign));
    }

    /**
     * The campaign's products, as ordinary ProductResources with live pricing.
     *
     * Each product shows the discount that wins *globally*, which may well be a
     * stronger rule from outside this campaign. Campaign membership answers "why is
     * this here?"; the pricing engine independently answers "what does it cost?".
     */
    public function products(IndexProductsRequest $request, string $slug): AnonymousResourceCollection|JsonResponse
    {
        $filters = array_filter([
            'brand_id' => $request->integer('brand_id') ?: null,
            'category_id' => $request->integer('category_id') ?: null,
            'min_price' => $request->has('min_price') ? $request->integer('min_price') : null,
            'max_price' => $request->has('max_price') ? $request->integer('max_price') : null,
            'search' => $request->string('search')->trim()->toString() ?: null,
            'sort' => $request->string('sort')->trim()->toString() ?: null,
            'available' => $request->has('available') ? $request->string('available')->toString() === 'true' : null,
        ], fn ($v) => $v !== null);

        $paginator = $this->catalog->getCampaignProducts($slug, $filters, $request->integer('per_page', 15));

        if ($paginator === null) {
            return response()->json(['message' => 'Campaign not found.'], 404);
        }

        return ProductResource::collection($paginator);
    }
}
