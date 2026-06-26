<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('sku');
            $table->string('product_title');
            $table->json('variant_attributes')->nullable();
            $table->integer('quantity');
            $table->integer('price_per_unit');
            $table->integer('line_total');
            $table->timestamps();

            $table->index(['order_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
