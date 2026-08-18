<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_daily_sales', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('paid_orders_count')->default(0);
            $table->unsignedInteger('cancelled_orders_count')->default(0);
            $table->unsignedBigInteger('gross_revenue')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('coupon_amount')->default(0);
            $table->unsignedBigInteger('refund_amount')->default(0);
            $table->bigInteger('net_revenue')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_daily_sales');
    }
};
