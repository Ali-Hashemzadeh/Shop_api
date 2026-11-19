<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Requests\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Timezone-consistent `date_from` / `date_to` handling for the monitoring
 * filters. A plain date (`2026-11-11`) covers the whole day in the application
 * timezone (`date_from` → 00:00:00, `date_to` → 23:59:59); a full ISO-8601
 * datetime is used as given, honouring its offset. Both are converted to UTC,
 * the timezone timestamps are stored in.
 */
trait ParsesDateRange
{
    /**
     * @return array<string, list<string>>
     */
    protected function dateRangeRules(): array
    {
        return [
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
        ];
    }

    /**
     * @return array{date_from?: CarbonInterface, date_to?: CarbonInterface}
     */
    protected function dateRange(): array
    {
        $range = [];

        foreach (['date_from' => false, 'date_to' => true] as $key => $endOfDay) {
            $value = $this->validated($key);

            if (! is_string($value) || $value === '') {
                continue;
            }

            $tz = (string) config('app.timezone', 'UTC');

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $date = Carbon::createFromFormat('Y-m-d', $value, $tz);
                $date = $endOfDay ? $date->endOfDay() : $date->startOfDay();
            } else {
                $date = Carbon::parse($value, $tz);
            }

            $range[$key] = $date->utc();
        }

        return $range;
    }
}
