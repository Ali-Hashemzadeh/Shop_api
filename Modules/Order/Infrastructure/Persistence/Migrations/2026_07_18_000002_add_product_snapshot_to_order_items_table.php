<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Immutable snapshot of the purchased product, captured from the enriched
            // CartItemDTO at checkout. Later Catalog edits must not alter historical
            // order records.
            $table->json('product_snapshot')->nullable()->after('variant_attributes');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('product_snapshot');
        });
    }
};
