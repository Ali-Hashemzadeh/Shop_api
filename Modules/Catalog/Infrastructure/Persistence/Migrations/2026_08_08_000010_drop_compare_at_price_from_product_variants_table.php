<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire Catalog's promotional price column.
 *
 * `compare_at_price` was a per-variant "was" price used to imply a discount.
 * Promotional pricing now lives entirely in the Promotion module, where a rule can
 * span many products, expire on a schedule, and be compared against competing
 * rules — none of which a second price column can express. Catalog keeps only
 * `base_price`, the regular price.
 *
 * Ordered after the Promotion tables (…_0000{1..6}) so the replacement exists
 * before the old mechanism is removed.
 *
 * Deliberately NOT touching order_items.compare_at_price: those rows are frozen
 * historical records of what was displayed at checkout, and rewriting them would
 * falsify past orders. New orders simply stop populating it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product_variants', 'compare_at_price')) {
            return;
        }

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('compare_at_price');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_variants', 'compare_at_price')) {
            return;
        }

        Schema::table('product_variants', function (Blueprint $table) {
            // Restored as nullable only — the historical values are gone, and they
            // must not be reconstructed from live Promotion rules.
            $table->integer('compare_at_price')->nullable()->after('base_price');
        });
    }
};
