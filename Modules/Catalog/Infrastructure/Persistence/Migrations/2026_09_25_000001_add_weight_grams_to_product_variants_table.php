<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-variant shipping weight, stored in whole grams (never decimal kilograms —
     * grams keep every weight an exact integer, consistent with the Money Unit Rule's
     * "no floats for anything that gets summed and priced" discipline).
     *
     * Nullable: existing variants predate weight capture, and the Post shipping
     * calculator treats a null/zero total weight as "cannot price dynamically" and
     * falls back to the configured static method price. Additive and production-safe.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('weight_grams')->nullable()->after('base_price');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('weight_grams');
        });
    }
};
