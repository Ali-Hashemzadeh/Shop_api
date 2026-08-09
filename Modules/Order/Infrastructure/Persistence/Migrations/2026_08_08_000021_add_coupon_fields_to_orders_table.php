<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order-level coupon state.
 *
 * A coupon reduces the order as a whole and is deliberately NOT allocated across
 * items: spreading 100,000 rials over ten lines would invent per-item prices that
 * no rule produced and that refunds would then have to un-invent.
 *
 * `payment_pricing_finalized_at` is the freeze marker. An order may have many
 * payment attempts, and every one of them must charge the same amount, so the
 * first attempt to reach pricing finalization locks the coupon decision (including
 * the decision to use none) and nothing may change it afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Normalized code, stored for display and for retry comparison.
            $table->string('coupon_code', 32)->nullable()->after('tax_amount');
            $table->integer('coupon_discount_amount')->default(0)->after('coupon_code');
            // Immutable payload so a historical order renders its coupon line without
            // consulting the live coupon or discount row.
            $table->json('coupon_snapshot')->nullable()->after('coupon_discount_amount');
            $table->timestamp('payment_pricing_finalized_at')->nullable()->after('coupon_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'coupon_code',
                'coupon_discount_amount',
                'coupon_snapshot',
                'payment_pricing_finalized_at',
            ]);
        });
    }
};
