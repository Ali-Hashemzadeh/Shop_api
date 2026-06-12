<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            // Marks exactly one variant per product as the default storefront fallback.
            // Enforced as a single-true invariant at the application layer, not by a DB constraint.
            $table->boolean('is_default')->default(false)->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
