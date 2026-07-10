<?php

declare(strict_types=1);

namespace Modules\Order\Application\Actions;

use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Order\Domain\Enums\OrderStatus;
use Modules\Order\Domain\Models\OrderItem;

class SyncSalesCountsAction
{
    public function __construct(
        private readonly CatalogManagerInterface $catalog,
    ) {}

    /**
     * Recompute units-sold per SKU from realized orders (Order's own tables only)
     * and push the absolute tally across the boundary to Catalog, which keeps its
     * denormalized products.sales_count in sync for the `?sort=most_sold` listing.
     *
     * @return int number of distinct SKUs pushed to Catalog
     */
    public function handle(): int
    {
        $totals = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', OrderStatus::soldStatuses())
            ->groupBy('order_items.sku')
            ->selectRaw('order_items.sku as sku, SUM(order_items.quantity) as total')
            ->pluck('total', 'sku')
            ->map(fn ($total) => (int) $total)
            ->all();

        $this->catalog->syncSalesCounts($totals);

        return count($totals);
    }
}
