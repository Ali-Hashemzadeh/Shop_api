<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('coupon_id')
                ->constrained('coupons')
                ->cascadeOnDelete();

            // Loose primitive references into Order and Identity — no cross-module FKs.
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id');

            // reserved | redeemed | released
            $table->string('status', 20);

            // The frozen rial reduction this claim represents.
            $table->integer('discount_amount');

            $table->timestamps();

            // One coupon lifecycle per order — the DB's final guarantee behind the
            // row-locked reservation path, and the enforcement of one-coupon-per-order.
            $table->unique('order_id');
            $table->index(['coupon_id', 'status']);
            $table->index(['coupon_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
