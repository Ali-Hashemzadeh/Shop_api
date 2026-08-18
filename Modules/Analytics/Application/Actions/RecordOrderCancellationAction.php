<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Analytics\Domain\Models\AnalyticsDailySale;
use Modules\Analytics\Domain\Models\AnalyticsProcessedEvent;
use Modules\Order\Domain\Events\OrderCancelledEvent;

class RecordOrderCancellationAction
{
    public function handle(OrderCancelledEvent $event): void
    {
        DB::transaction(function () use ($event) {
            if (AnalyticsProcessedEvent::where('event_id', $event->eventId)->lockForUpdate()->exists()) {
                return;
            }

            $date = $event->cancelledAt ? Carbon::parse($event->cancelledAt)->toDateString() : now()->toDateString();

            $dailySale = AnalyticsDailySale::lockForUpdate()->firstOrCreate(
                ['date' => $date],
                [
                    'orders_count' => 0,
                    'paid_orders_count' => 0,
                    'cancelled_orders_count' => 0,
                    'gross_revenue' => 0,
                    'discount_amount' => 0,
                    'coupon_amount' => 0,
                    'refund_amount' => 0,
                    'net_revenue' => 0,
                ]
            );

            $dailySale->cancelled_orders_count += 1;

            if ($event->wasPaid && $event->refundAmount > 0) {
                $dailySale->refund_amount += $event->refundAmount;
                $dailySale->net_revenue -= $event->refundAmount;
            } elseif ($event->refundAmount > 0) {
                $dailySale->refund_amount += $event->refundAmount;
            }

            $dailySale->save();

            AnalyticsProcessedEvent::create([
                'event_id' => $event->eventId,
                'event_name' => OrderCancelledEvent::class,
                'processed_at' => now(),
            ]);
        });
    }
}
