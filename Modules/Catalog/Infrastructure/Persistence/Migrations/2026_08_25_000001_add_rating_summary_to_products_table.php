<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalized rating counters on products, mirroring `sales_count` structurally:
 * raw integers pushed in by the Review module's hourly
 * `reviews:sync-product-ratings` through
 * CatalogManagerInterface::syncRatingSummary() — never client-accepted.
 *
 * The average is derived at read time (`rating_sum / rating_count`), so it can
 * never drift from its inputs. Only approved + rated reviews feed these counts;
 * an approved comment without a rating leaves them untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('rating_sum')->default(0)->after('sales_count');
            $table->unsignedInteger('rating_count')->default(0)->after('rating_sum');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['rating_sum', 'rating_count']);
        });
    }
};
