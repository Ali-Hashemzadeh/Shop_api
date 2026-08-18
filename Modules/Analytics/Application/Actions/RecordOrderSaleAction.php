<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Analytics\Domain\Models\AnalyticsCategorySale;
use Modules\Analytics\Domain\Models\AnalyticsCouponUsage;
use Modules\Analytics\Domain\Models\AnalyticsCustomerStat;
use Modules\Analytics\Domain\Models\AnalyticsDailySale;
use Modules\Analytics\Domain\Models\AnalyticsDiscountUsage;
use Modules\Analytics\Domain\Models\AnalyticsProcessedEvent;
use Modules\Analytics\Domain\Models\AnalyticsProductCategory;
use Modules\Analytics\Domain\Models\AnalyticsProductSale;
use Modules\Analytics\Domain\Models\AnalyticsVariantSale;
use Modules\Order\Domain\Events\OrderPaidEvent;

class RecordOrderSaleAction
{
    public function handle(OrderPaidEvent $event): void
    {
        DB::transaction(function () use ($event) {
            if (AnalyticsProcessedEvent::where('event_id', $event->eventId)->lockForUpdate()->exists()) {
                return;
            }

            $date = $event->paidAt ? Carbon::parse($event->paidAt)->toDateString() : now()->toDateString();

            // 1. Calculate items gross revenue
            $totalGrossRevenue = 0;
            foreach ($event->items as $item) {
                $regularPrice = $item->regularUnitPrice > 0 ? $item->regularUnitPrice : $item->unitPrice;
                $totalGrossRevenue += $regularPrice * $item->quantity;
            }
            if ($totalGrossRevenue === 0) {
                $totalGrossRevenue = $event->totalAmount + $event->discountAmount + $event->couponAmount;
            }

            // 2. Update Daily Sales
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

            $dailySale->orders_count += 1;
            $dailySale->paid_orders_count += 1;
            $dailySale->gross_revenue += $totalGrossRevenue;
            $dailySale->discount_amount += $event->discountAmount;
            $dailySale->coupon_amount += $event->couponAmount;
            $dailySale->net_revenue += $event->totalAmount;
            $dailySale->save();

            // 3. Process each purchased item
            foreach ($event->items as $item) {
                $regularPrice = $item->regularUnitPrice > 0 ? $item->regularUnitPrice : $item->unitPrice;
                $itemGross = $regularPrice * $item->quantity;
                $itemNet = $item->unitPrice * $item->quantity;

                // Product sales
                if ($item->productId > 0) {
                    $productSale = AnalyticsProductSale::lockForUpdate()->firstOrCreate(
                        ['date' => $date, 'product_id' => $item->productId],
                        [
                            'quantity_sold' => 0,
                            'orders_count' => 0,
                            'gross_revenue' => 0,
                            'discount_amount' => 0,
                            'net_revenue' => 0,
                        ]
                    );
                    $productSale->quantity_sold += $item->quantity;
                    $productSale->orders_count += 1;
                    $productSale->gross_revenue += $itemGross;
                    $productSale->discount_amount += $item->discountAmount;
                    $productSale->net_revenue += $itemNet;
                    $productSale->save();
                }

                // Variant sales
                if ($item->variantId > 0) {
                    $variantSale = AnalyticsVariantSale::lockForUpdate()->firstOrCreate(
                        ['date' => $date, 'variant_id' => $item->variantId],
                        [
                            'quantity_sold' => 0,
                            'orders_count' => 0,
                            'gross_revenue' => 0,
                            'discount_amount' => 0,
                            'net_revenue' => 0,
                        ]
                    );
                    $variantSale->quantity_sold += $item->quantity;
                    $variantSale->orders_count += 1;
                    $variantSale->gross_revenue += $itemGross;
                    $variantSale->discount_amount += $item->discountAmount;
                    $variantSale->net_revenue += $itemNet;
                    $variantSale->save();
                }

                // Category sales across full hierarchy
                foreach ($item->categoryIds as $categoryId) {
                    if ($categoryId <= 0) {
                        continue;
                    }

                    $categorySale = AnalyticsCategorySale::lockForUpdate()->firstOrCreate(
                        ['date' => $date, 'category_id' => $categoryId],
                        [
                            'quantity_sold' => 0,
                            'orders_count' => 0,
                            'gross_revenue' => 0,
                            'discount_amount' => 0,
                            'net_revenue' => 0,
                        ]
                    );
                    $categorySale->quantity_sold += $item->quantity;
                    $categorySale->orders_count += 1;
                    $categorySale->gross_revenue += $itemGross;
                    $categorySale->discount_amount += $item->discountAmount;
                    $categorySale->net_revenue += $itemNet;
                    $categorySale->save();

                    // Record product-category association for independent filtering
                    if ($item->productId > 0) {
                        AnalyticsProductCategory::firstOrCreate([
                            'product_id' => $item->productId,
                            'category_id' => $categoryId,
                        ]);
                    }
                }

                // Discount usage
                if ($item->discountId !== null && $item->discountId > 0) {
                    $discountUsage = AnalyticsDiscountUsage::lockForUpdate()->firstOrCreate(
                        ['date' => $date, 'discount_id' => $item->discountId],
                        [
                            'usage_count' => 0,
                            'discount_amount' => 0,
                            'generated_revenue' => 0,
                        ]
                    );
                    $discountUsage->usage_count += 1;
                    $discountUsage->discount_amount += $item->discountAmount;
                    $discountUsage->generated_revenue += $itemNet;
                    $discountUsage->save();
                }
            }

            // 4. Coupon usage
            if ($event->couponId !== null && $event->couponId > 0) {
                $couponUsage = AnalyticsCouponUsage::lockForUpdate()->firstOrCreate(
                    ['date' => $date, 'coupon_id' => $event->couponId],
                    [
                        'usage_count' => 0,
                        'discount_amount' => 0,
                        'generated_revenue' => 0,
                    ]
                );
                $couponUsage->usage_count += 1;
                $couponUsage->discount_amount += $event->couponAmount;
                $couponUsage->generated_revenue += $event->totalAmount;
                $couponUsage->save();
            }

            // 5. Customer stats
            if ($event->userId > 0) {
                $customerStat = AnalyticsCustomerStat::lockForUpdate()->firstOrCreate(
                    ['customer_id' => $event->userId],
                    [
                        'orders_count' => 0,
                        'total_spent' => 0,
                        'total_discount_received' => 0,
                        'average_order_value' => 0,
                        'first_order_at' => now(),
                        'last_order_at' => now(),
                    ]
                );

                $customerStat->orders_count += 1;
                $customerStat->total_spent += $event->totalAmount;
                $customerStat->total_discount_received += ($event->discountAmount + $event->couponAmount);
                $customerStat->average_order_value = intdiv((int) $customerStat->total_spent, (int) $customerStat->orders_count);
                $customerStat->last_order_at = now();
                if ($customerStat->first_order_at === null) {
                    $customerStat->first_order_at = now();
                }
                $customerStat->save();
            }

            AnalyticsProcessedEvent::create([
                'event_id' => $event->eventId,
                'event_name' => OrderPaidEvent::class,
                'processed_at' => now(),
            ]);
        });
    }
}
