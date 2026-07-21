<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Modules\Shipment\Application\Actions\GenerateDeliverySlotsAction;
use Modules\Shipment\Domain\Models\DeliveryWorkingPeriod;

/**
 * Seeds the *default* recurring local-delivery working periods and materializes the
 * first batch of dated sessions from them.
 *
 * These are starting values, not fixed policy: the admin owns the schedule and edits
 * it through /api/v1/admin/shipment/delivery-working-periods. Every row is written
 * with firstOrCreate keyed on (weekday, starts_at, ends_at), so re-running the seeder
 * never duplicates a period and never flips is_active back on for a period the admin
 * deactivated.
 */
class ShipmentScheduleSeeder extends Seeder
{
    /**
     * Weekday numbers follow Carbon::dayOfWeek (0 = Sunday … 6 = Saturday), the same
     * convention DeliverySlotGenerator matches dates against. The default work week is
     * Saturday–Thursday with a morning and an evening shift; Friday is closed.
     */
    private const WORKING_WEEKDAYS = [6, 0, 1, 2, 3, 4];

    private const SHIFTS = [
        ['09:00:00', '13:00:00'],
        ['16:00:00', '21:00:00'],
    ];

    public function run(): void
    {
        $created = 0;

        foreach (self::WORKING_WEEKDAYS as $weekday) {
            foreach (self::SHIFTS as [$startsAt, $endsAt]) {
                $period = DeliveryWorkingPeriod::firstOrCreate(
                    ['weekday' => $weekday, 'starts_at' => $startsAt, 'ends_at' => $endsAt],
                    ['is_active' => true],
                );

                if ($period->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        $this->command->info("Delivery working periods seeded: {$created} new period(s) (Sat–Thu, two shifts, Friday closed).");

        // Materialize dated sessions from the templates so local delivery is bookable
        // immediately after a fresh seed. Idempotent — identical to the daily command.
        $slots = app(GenerateDeliverySlotsAction::class)->handle();

        $this->command->info("Delivery slots generated: {$slots} new session(s).");
    }
}
