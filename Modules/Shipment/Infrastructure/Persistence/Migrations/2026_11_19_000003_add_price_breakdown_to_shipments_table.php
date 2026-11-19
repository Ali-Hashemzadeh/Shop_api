<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive, nullable: the itemised postage (tomans) frozen at checkout and copied
     * from the order's shipment snapshot when the shipment is activated. Existing
     * shipments keep NULL — they are never repriced or backfilled.
     */
    public function up(): void
    {
        if (Schema::hasColumn('shipments', 'price_breakdown')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table) {
            $table->json('price_breakdown')->nullable()->after('shipping_cost');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('shipments', 'price_breakdown')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropColumn('price_breakdown');
            });
        }
    }
};
