<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('public_code', 32)->unique();

            // Loose cross-module references (no cascading FKs to other modules).
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('user_id')->index();

            $table->string('method_code');
            $table->string('method_title');
            $table->string('method_type');
            $table->integer('shipping_cost')->default(0);

            $table->string('status')->default('pending')->index();

            $table->json('address_snapshot')->nullable();
            $table->json('delivery_slot_snapshot')->nullable();
            $table->json('pickup_location_snapshot')->nullable();

            $table->string('carrier_name')->nullable();
            $table->string('tracking_number')->nullable()->index();

            // Loose media references (Media coupling rule).
            $table->unsignedBigInteger('postal_receipt_media_id')->nullable();
            $table->unsignedBigInteger('proof_media_id')->nullable();

            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('handed_to_post_at')->nullable();
            $table->timestamp('out_for_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('ready_for_pickup_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->string('receiver_name')->nullable();
            $table->string('failure_reason')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
