<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // Loose Identity reference — indexed because the driver API filters on
            // it on every request, but never an FK: Shipment does not own users.
            $table->unsignedBigInteger('assigned_delivery_user_id')->nullable()->index()->after('user_id');
            $table->timestamp('delivery_assigned_at')->nullable()->after('assigned_delivery_user_id');

            // The customer's handoff code, stored as a hash and nothing else. The
            // plaintext exists only in the SMS that carries it to the customer —
            // it is never written to this table, to shipment history, to a stored
            // notification, or to a log.
            $table->string('delivery_verification_code_hash')->nullable()->after('proof_media_id');
            $table->timestamp('delivery_verification_issued_at')->nullable()->after('delivery_verification_code_hash');
            $table->timestamp('delivery_verification_verified_at')->nullable()->after('delivery_verification_issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['assigned_delivery_user_id']);
            $table->dropColumn([
                'assigned_delivery_user_id',
                'delivery_assigned_at',
                'delivery_verification_code_hash',
                'delivery_verification_issued_at',
                'delivery_verification_verified_at',
            ]);
        });
    }
};
