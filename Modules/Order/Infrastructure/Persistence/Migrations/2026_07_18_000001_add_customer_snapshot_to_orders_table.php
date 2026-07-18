<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Immutable snapshot of the customer's identity at checkout, captured via
            // IdentityManagerInterface::getUserSummary(). Later profile edits must not
            // alter historical order records.
            $table->json('customer_snapshot')->nullable()->after('shipment_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('customer_snapshot');
        });
    }
};
