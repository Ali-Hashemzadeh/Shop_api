<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze the automatic discount that applied to each item at checkout.
 *
 * Automatic discounts are evaluated live everywhere else, but an order is a
 * financial record: it must stay explainable after the rule is edited, expired, or
 * soft-deleted, so the outcome is copied here and never recomputed.
 *
 * Existing rows get regular_price_per_unit = price_per_unit and a zero reduction,
 * which is exactly true of them — they were placed before promotions existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Catalog's base_price at checkout — the strike-through figure.
            $table->integer('regular_price_per_unit')->default(0)->after('quantity');
            // regular_price_per_unit − price_per_unit, per unit.
            $table->integer('automatic_discount_amount_per_unit')->default(0)->after('regular_price_per_unit');
            // Self-contained explanation of the winning rule; see
            // AutomaticDiscountResultDTO::toSnapshot().
            $table->json('automatic_discount_snapshot')->nullable()->after('automatic_discount_amount_per_unit');
        });

        // Backfill: pre-promotion orders were sold at their regular price.
        Schema::hasColumn('order_items', 'regular_price_per_unit')
            && DB::table('order_items')
                ->where('regular_price_per_unit', 0)
                ->update(['regular_price_per_unit' => DB::raw('price_per_unit')]);
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn([
                'regular_price_per_unit',
                'automatic_discount_amount_per_unit',
                'automatic_discount_snapshot',
            ]);
        });
    }
};
