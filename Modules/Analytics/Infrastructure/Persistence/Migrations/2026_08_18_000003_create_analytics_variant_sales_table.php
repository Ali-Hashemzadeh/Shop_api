<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_variant_sales', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedBigInteger('variant_id')->index();
            $table->unsignedInteger('quantity_sold')->default(0);
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedBigInteger('gross_revenue')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->bigInteger('net_revenue')->default(0);
            $table->timestamps();

            $table->unique(['date', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_variant_sales');
    }
};
