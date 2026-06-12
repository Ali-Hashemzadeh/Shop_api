<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->string('sku')->unique(); // Unique stock keeping unit

            // Core engineering rule: Store currency in raw cents
            $table->integer('base_price');
            $table->integer('compare_at_price')->nullable(); // Original cross-out price

            // Loose relationship giving this specific variant its own unique display image
            $table->unsignedBigInteger('media_id')->nullable();

            // Structured selection storage (e.g., {"size": "L", "color": "red"})
            $table->json('attributes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
