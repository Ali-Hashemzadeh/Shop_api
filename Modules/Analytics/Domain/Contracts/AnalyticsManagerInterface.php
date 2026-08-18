<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Analytics\Domain\DTOs\AnalyticsCustomerStatsDTO;
use Modules\Analytics\Domain\DTOs\AnalyticsDailySalesDTO;
use Modules\Analytics\Domain\DTOs\AnalyticsDashboardDTO;
use Modules\Analytics\Domain\DTOs\AnalyticsDeliveryStatsDTO;
use Modules\Analytics\Domain\DTOs\AnalyticsProductSalesDTO;

interface AnalyticsManagerInterface
{
    /** Get dashboard overview summary data. */
    public function getDashboard(): AnalyticsDashboardDTO;

    /**
     * Get daily sales analytics report.
     *
     * @param  array{from?: ?string, to?: ?string}  $filters
     */
    public function getSales(array $filters = []): AnalyticsDailySalesDTO;

    /**
     * Get best selling products and variants analytics.
     *
     * @param  array{from?: ?string, to?: ?string, category_id?: ?int}  $filters
     */
    public function getProducts(array $filters = []): AnalyticsProductSalesDTO;

    /**
     * Get customer statistics paginated.
     *
     * @param  array{sort?: ?string, direction?: ?string}  $filters
     * @return LengthAwarePaginator<AnalyticsCustomerStatsDTO>
     */
    public function getCustomers(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Get delivery and courier performance statistics.
     *
     * @param  array{from?: ?string, to?: ?string, method?: ?string, driver_id?: ?int}  $filters
     */
    public function getDelivery(array $filters = []): AnalyticsDeliveryStatsDTO;
}
