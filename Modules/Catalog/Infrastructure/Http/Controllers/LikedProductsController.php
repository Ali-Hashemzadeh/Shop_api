<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\ProductDTO;
use Modules\Catalog\Infrastructure\Http\Concerns\InteractsWithWishlistState;
use Modules\Catalog\Infrastructure\Http\Resources\ProductResource;
use Modules\Wishlist\Domain\Contracts\WishlistManagerInterface;

/**
 * The authenticated customer's liked products, paginated and rendered with the
 * ordinary ProductResource (same representation, live pricing, and per-page
 * enrichment as any product listing).
 *
 * Wishlist paginates the user's own like rows (newest first) — the paginator's
 * totals come from the likes table — and Catalog hydrates the page's product
 * ids in one batched call. A like whose product is no longer published simply
 * drops out of the page rather than appearing as a broken row.
 */
class LikedProductsController extends Controller
{
    use InteractsWithWishlistState;

    public function __construct(
        private readonly WishlistManagerInterface $wishlist,
        private readonly CatalogManagerInterface $catalog,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = max(1, min(100, $request->integer('per_page', 15)));

        $page = $this->wishlist->paginateLikedProductIds((int) $request->user()->id, $perPage);

        $products = $this->catalog->getPublishedProductsByIds($page->items());

        // Preserve the like ordering, drop ids that no longer resolve to a
        // published product, and keep the paginator meta (total = like count).
        $page->setCollection(
            collect($page->items())
                ->map(fn (int $id): ?ProductDTO => $products[$id] ?? null)
                ->filter()
                ->values()
        );

        $this->annotateProductWishlistState($request, $page->getCollection());

        return ProductResource::collection($page);
    }
}
