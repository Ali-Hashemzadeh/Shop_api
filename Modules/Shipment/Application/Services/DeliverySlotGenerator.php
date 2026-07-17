<?php

declare(strict_types=1);

namespace Modules\Shipment\Application\Services;

use Carbon\Carbon;
use Modules\Shipment\Domain\Enums\DeliverySlotStatus;
use Modules\Shipment\Domain\Models\DeliveryScheduleException;
use Modules\Shipment\Domain\Models\DeliverySlot;
use Modules\Shipment\Domain\Models\DeliveryWorkingPeriod;

/**
 * Generates dated delivery sessions from recurring weekly working-period templates,
 * following the cinema-session principle. Idempotent and safe to run daily: it never
 * duplicates, overwrites operator-modified slots, or reopens closed slots.
 */
class DeliverySlotGenerator
{
    /**
     * @return int number of newly-created slots
     */
    public function generate(int $days): int
    {
        $duration = (int) config('shipment.delivery.slot_duration_minutes', 90);
        $minFinal = (int) config('shipment.delivery.minimum_final_slot_minutes', 60);

        $periodsByWeekday = DeliveryWorkingPeriod::where('is_active', true)
            ->get()
            ->groupBy('weekday');

        $created = 0;
        $start = Carbon::today();

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->copy()->addDays($offset);
            $dateString = $date->toDateString();

            $exceptions = DeliveryScheduleException::whereDate('date', $dateString)->get();

            // A closed exception cancels the whole day.
            if ($exceptions->firstWhere('type', 'closed')) {
                continue;
            }

            // Custom-hours exceptions replace the recurring template for that date.
            $customHours = $exceptions->where('type', 'custom_hours');

            if ($customHours->isNotEmpty()) {
                $periods = $customHours->map(fn (DeliveryScheduleException $e) => [
                    'starts_at' => $e->starts_at,
                    'ends_at' => $e->ends_at,
                ]);
            } else {
                $periods = ($periodsByWeekday[$date->dayOfWeek] ?? collect())->map(fn (DeliveryWorkingPeriod $p) => [
                    'starts_at' => $p->starts_at,
                    'ends_at' => $p->ends_at,
                ]);
            }

            foreach ($periods as $period) {
                if (empty($period['starts_at']) || empty($period['ends_at'])) {
                    continue;
                }

                $created += $this->generatePeriod($dateString, (string) $period['starts_at'], (string) $period['ends_at'], $duration, $minFinal);
            }
        }

        return $created;
    }

    private function generatePeriod(string $date, string $startsAt, string $endsAt, int $duration, int $minFinal): int
    {
        $cursor = Carbon::parse($date.' '.substr($startsAt, 0, 8));
        $end = Carbon::parse($date.' '.substr($endsAt, 0, 8));
        $capacity = (int) config('shipment.delivery.default_capacity', 3);

        $created = 0;

        while ($cursor->lt($end)) {
            $slotEnd = $cursor->copy()->addMinutes($duration);

            if ($slotEnd->gt($end)) {
                // Final partial slice: only keep it if it meets the minimum duration.
                $remainingMinutes = $cursor->diffInMinutes($end);

                if ($remainingMinutes < $minFinal) {
                    break;
                }

                $slotEnd = $end->copy();
            }

            $startTime = $cursor->format('H:i:s');
            $endTime = $slotEnd->format('H:i:s');

            $exists = DeliverySlot::where('delivery_date', $date)
                ->where('starts_at', $startTime)
                ->where('ends_at', $endTime)
                ->exists();

            if (! $exists) {
                DeliverySlot::create([
                    'delivery_date' => $date,
                    'starts_at' => $startTime,
                    'ends_at' => $endTime,
                    'capacity' => $capacity,
                    'admin_reserved_capacity' => 0,
                    'status' => DeliverySlotStatus::Open->value,
                ]);
                $created++;
            }

            $cursor = $slotEnd;
        }

        return $created;
    }
}
