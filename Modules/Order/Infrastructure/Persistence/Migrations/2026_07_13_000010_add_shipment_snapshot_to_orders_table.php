<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // New source of truth for the selected fulfillment method. The legacy
            // nullable `shipment_method_id` column is preserved for backward
            // compatibility but is no longer written by the checkout flow.
            $table->string('shipment_method_code')->nullable()->after('shipment_method_id');
            // Immutable snapshot of the shipment selection, captured at checkout.
            $table->json('shipment_snapshot')->nullable()->after('shipping_address');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipment_method_code', 'shipment_snapshot']);
        });
    }
};
