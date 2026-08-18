<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Analytics\Domain\Models\AnalyticsDeliveryStat;
use Modules\Analytics\Domain\Models\AnalyticsDriverStat;
use Modules\Analytics\Domain\Models\AnalyticsProcessedEvent;
use Modules\Shipment\Domain\Events\ShipmentAssignedToDeliveryEvent;
use Modules\Shipment\Domain\Events\ShipmentDeliveredEvent;
use Modules\Shipment\Domain\Events\ShipmentDeliveryFailedEvent;
use Modules\Shipment\Domain\Events\ShipmentHandedToPostEvent;

class RecordShipmentStatsAction
{
    public function handleAssigned(ShipmentAssignedToDeliveryEvent $event): void
    {
        $date = $event->deliveryDate ?: now()->toDateString();

        DB::transaction(function () use ($date, $event) {
            if (AnalyticsProcessedEvent::where('event_id', $event->eventId)->lockForUpdate()->exists()) {
                return;
            }

            // Delivery method stats
            $deliveryStat = AnalyticsDeliveryStat::lockForUpdate()->firstOrCreate(
                ['date' => $date, 'method' => 'local_delivery'],
                [
                    'assigned_count' => 0,
                    'delivered_count' => 0,
                    'failed_count' => 0,
                    'total_delivery_minutes' => 0,
                ]
            );
            $deliveryStat->assigned_count += 1;
            $deliveryStat->save();

            // Driver stats
            if ($event->deliveryUserId > 0) {
                $driverStat = AnalyticsDriverStat::lockForUpdate()->firstOrCreate(
                    ['date' => $date, 'driver_id' => $event->deliveryUserId],
                    [
                        'assigned_count' => 0,
                        'completed_count' => 0,
                        'failed_count' => 0,
                        'total_delivery_minutes' => 0,
                    ]
                );
                $driverStat->assigned_count += 1;
                $driverStat->save();
            }

            AnalyticsProcessedEvent::create([
                'event_id' => $event->eventId,
                'event_name' => ShipmentAssignedToDeliveryEvent::class,
                'processed_at' => now(),
            ]);
        });
    }

    public function handleHandedToPost(ShipmentHandedToPostEvent $event): void
    {
        $date = now()->toDateString();
        $method = 'post_standard';

        DB::transaction(function () use ($date, $method, $event) {
            if (AnalyticsProcessedEvent::where('event_id', $event->eventId)->lockForUpdate()->exists()) {
                return;
            }

            $deliveryStat = AnalyticsDeliveryStat::lockForUpdate()->firstOrCreate(
                ['date' => $date, 'method' => $method],
                [
                    'assigned_count' => 0,
                    'delivered_count' => 0,
                    'failed_count' => 0,
                    'total_delivery_minutes' => 0,
                ]
            );
            $deliveryStat->assigned_count += 1;
            $deliveryStat->delivered_count += 1;
            $deliveryStat->save();

            AnalyticsProcessedEvent::create([
                'event_id' => $event->eventId,
                'event_name' => ShipmentHandedToPostEvent::class,
                'processed_at' => now(),
            ]);
        });
    }

    public function handleDelivered(ShipmentDeliveredEvent $event): void
    {
        $date = $event->deliveredAt ? Carbon::parse($event->deliveredAt)->toDateString() : now()->toDateString();
        $method = $event->method ?: 'local_delivery';
        $duration = $event->deliveryMinutes ?? 0;

        DB::transaction(function () use ($date, $method, $duration, $event) {
            if (AnalyticsProcessedEvent::where('event_id', $event->eventId)->lockForUpdate()->exists()) {
                return;
            }

            $deliveryStat = AnalyticsDeliveryStat::lockForUpdate()->firstOrCreate(
                ['date' => $date, 'method' => $method],
                [
                    'assigned_count' => 0,
                    'delivered_count' => 0,
                    'failed_count' => 0,
                    'total_delivery_minutes' => 0,
                ]
            );

            if ($duration > 0) {
                $deliveryStat->total_delivery_minutes += $duration;
            }

            $deliveryStat->delivered_count += 1;
            if ($deliveryStat->assigned_count < $deliveryStat->delivered_count) {
                $deliveryStat->assigned_count = $deliveryStat->delivered_count;
            }
            $deliveryStat->save();

            if ($event->driverId !== null && $event->driverId > 0) {
                $driverStat = AnalyticsDriverStat::lockForUpdate()->firstOrCreate(
                    ['date' => $date, 'driver_id' => $event->driverId],
                    [
                        'assigned_count' => 0,
                        'completed_count' => 0,
                        'failed_count' => 0,
                        'total_delivery_minutes' => 0,
                    ]
                );

                if ($duration > 0) {
                    $driverStat->total_delivery_minutes += $duration;
                }

                $driverStat->completed_count += 1;
                if ($driverStat->assigned_count < $driverStat->completed_count) {
                    $driverStat->assigned_count = $driverStat->completed_count;
                }
                $driverStat->save();
            }

            AnalyticsProcessedEvent::create([
                'event_id' => $event->eventId,
                'event_name' => ShipmentDeliveredEvent::class,
                'processed_at' => now(),
            ]);
        });
    }

    public function handleFailed(ShipmentDeliveryFailedEvent $event): void
    {
        $date = $event->failedAt ? Carbon::parse($event->failedAt)->toDateString() : now()->toDateString();
        $method = $event->method ?: 'local_delivery';

        DB::transaction(function () use ($date, $method, $event) {
            if (AnalyticsProcessedEvent::where('event_id', $event->eventId)->lockForUpdate()->exists()) {
                return;
            }

            $deliveryStat = AnalyticsDeliveryStat::lockForUpdate()->firstOrCreate(
                ['date' => $date, 'method' => $method],
                [
                    'assigned_count' => 0,
                    'delivered_count' => 0,
                    'failed_count' => 0,
                    'total_delivery_minutes' => 0,
                ]
            );
            $deliveryStat->failed_count += 1;
            $deliveryStat->save();

            if ($event->driverId !== null && $event->driverId > 0) {
                $driverStat = AnalyticsDriverStat::lockForUpdate()->firstOrCreate(
                    ['date' => $date, 'driver_id' => $event->driverId],
                    [
                        'assigned_count' => 0,
                        'completed_count' => 0,
                        'failed_count' => 0,
                        'total_delivery_minutes' => 0,
                    ]
                );
                $driverStat->failed_count += 1;
                $driverStat->save();
            }

            AnalyticsProcessedEvent::create([
                'event_id' => $event->eventId,
                'event_name' => ShipmentDeliveryFailedEvent::class,
                'processed_at' => now(),
            ]);
        });
    }
}
