<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Display-only checkout snapshot. Existing rows deliberately remain
            // null; historical prices must never be reconstructed from Catalog.
            $table->integer('compare_at_price')->nullable()->after('price_per_unit');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('compare_at_price');
        });
    }
};
