<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_customer_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->unique();
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedBigInteger('total_spent')->default(0);
            $table->unsignedBigInteger('total_discount_received')->default(0);
            $table->unsignedBigInteger('average_order_value')->default(0);
            $table->dateTime('first_order_at')->nullable();
            $table->dateTime('last_order_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_customer_stats');
    }
};
