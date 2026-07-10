<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Denormalized best-seller counter. Kept in sync from the Order module
            // via CatalogManagerInterface::syncSalesCounts(); never client-accepted.
            // Indexed so `?sort=most_sold` (ORDER BY sales_count DESC) stays cheap.
            $table->unsignedBigInteger('sales_count')->default(0)->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['sales_count']);
            $table->dropColumn('sales_count');
        });
    }
};
