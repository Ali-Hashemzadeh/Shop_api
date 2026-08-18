<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Analytics\Domain\Models\AnalyticsPaymentStat;
use Modules\Analytics\Domain\Models\AnalyticsProcessedEvent;
use Modules\Payment\Domain\Events\PaymentCancelledEvent;
use Modules\Payment\Domain\Events\PaymentFailedEvent;
use Modules\Payment\Domain\Events\PaymentSuccessfulEvent;

class RecordPaymentStatsAction
{
    public function handleSuccess(PaymentSuccessfulEvent $event): void
    {
        $date = $event->paidAt ? Carbon::parse($event->paidAt)->toDateString() : now()->toDateString();
        $gateway = $event->gateway ?: 'unknown';

        DB::transaction(function () use ($date, $gateway, $event) {
            if (AnalyticsProcessedEvent::where('event_id', $event->eventId)->lockForUpdate()->exists()) {
                return;
            }

            $stat = AnalyticsPaymentStat::lockForUpdate()->firstOrCreate(
                ['date' => $date, 'gateway' => $gateway],
                [
                    'successful_count' => 0,
                    'failed_count' => 0,
                    'cancelled_count' => 0,
                    'total_amount' => 0,
                ]
            );

            $stat->successful_count += 1;
            $stat->total_amount += $event->amount;
            $stat->save();

            AnalyticsProcessedEvent::create([
                'event_id' => $event->eventId,
                'event_name' => PaymentSuccessfulEvent::class,
                'processed_at' => now(),
            ]);
        });
    }

    public function handleFailed(PaymentFailedEvent $event): void
    {
        $date = now()->toDateString();
        $gateway = $event->gateway ?: 'unknown';

        DB::transaction(function () use ($date, $gateway, $event) {
            if (AnalyticsProcessedEvent::where('event_id', $event->eventId)->lockForUpdate()->exists()) {
                return;
            }

            $stat = AnalyticsPaymentStat::lockForUpdate()->firstOrCreate(
                ['date' => $date, 'gateway' => $gateway],
                [
                    'successful_count' => 0,
                    'failed_count' => 0,
                    'cancelled_count' => 0,
                    'total_amount' => 0,
                ]
            );

            $stat->failed_count += 1;
            $stat->save();

            AnalyticsProcessedEvent::create([
                'event_id' => $event->eventId,
                'event_name' => PaymentFailedEvent::class,
                'processed_at' => now(),
            ]);
        });
    }

    public function handleCancelled(PaymentCancelledEvent $event): void
    {
        $date = $event->cancelledAt ? Carbon::parse($event->cancelledAt)->toDateString() : now()->toDateString();
        $gateway = $event->gateway ?: 'unknown';

        DB::transaction(function () use ($date, $gateway, $event) {
            if (AnalyticsProcessedEvent::where('event_id', $event->eventId)->lockForUpdate()->exists()) {
                return;
            }

            $stat = AnalyticsPaymentStat::lockForUpdate()->firstOrCreate(
                ['date' => $date, 'gateway' => $gateway],
                [
                    'successful_count' => 0,
                    'failed_count' => 0,
                    'cancelled_count' => 0,
                    'total_amount' => 0,
                ]
            );

            $stat->cancelled_count += 1;
            $stat->save();

            AnalyticsProcessedEvent::create([
                'event_id' => $event->eventId,
                'event_name' => PaymentCancelledEvent::class,
                'processed_at' => now(),
            ]);
        });
    }
}
