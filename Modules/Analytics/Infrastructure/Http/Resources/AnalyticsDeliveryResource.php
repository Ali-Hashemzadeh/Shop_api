<?php

declare(strict_types=1);

namespace Modules\Analytics\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Analytics\Domain\DTOs\AnalyticsDeliveryStatsDTO;

/**
 * @property-read AnalyticsDeliveryStatsDTO $resource
 */
class AnalyticsDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
