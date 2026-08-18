<?php

declare(strict_types=1);

namespace Modules\Analytics\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Analytics\Domain\Contracts\AnalyticsManagerInterface;
use Modules\Analytics\Infrastructure\Http\Requests\AdminAnalyticsRequest;
use Modules\Analytics\Infrastructure\Http\Requests\IndexCustomerStatsRequest;
use Modules\Analytics\Infrastructure\Http\Requests\IndexDeliveryStatsRequest;
use Modules\Analytics\Infrastructure\Http\Requests\IndexProductSalesAnalyticsRequest;
use Modules\Analytics\Infrastructure\Http\Requests\IndexSalesAnalyticsRequest;
use Modules\Analytics\Infrastructure\Http\Resources\AnalyticsCustomerResource;
use Modules\Analytics\Infrastructure\Http\Resources\AnalyticsDashboardResource;
use Modules\Analytics\Infrastructure\Http\Resources\AnalyticsDeliveryResource;
use Modules\Analytics\Infrastructure\Http\Resources\AnalyticsProductsResource;
use Modules\Analytics\Infrastructure\Http\Resources\AnalyticsSalesResource;

class AdminAnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsManagerInterface $analytics,
    ) {}

    public function dashboard(AdminAnalyticsRequest $request): AnalyticsDashboardResource
    {
        $data = $this->analytics->getDashboard();

        return new AnalyticsDashboardResource($data);
    }

    public function sales(IndexSalesAnalyticsRequest $request): AnalyticsSalesResource
    {
        $data = $this->analytics->getSales($request->filters());

        return new AnalyticsSalesResource($data);
    }

    public function products(IndexProductSalesAnalyticsRequest $request): AnalyticsProductsResource
    {
        $data = $this->analytics->getProducts($request->filters());

        return new AnalyticsProductsResource($data);
    }

    public function customers(IndexCustomerStatsRequest $request): AnonymousResourceCollection
    {
        $paginator = $this->analytics->getCustomers($request->filters(), $request->perPage());

        return AnalyticsCustomerResource::collection($paginator);
    }

    public function delivery(IndexDeliveryStatsRequest $request): AnalyticsDeliveryResource
    {
        $data = $this->analytics->getDelivery($request->filters());

        return new AnalyticsDeliveryResource($data);
    }
}
