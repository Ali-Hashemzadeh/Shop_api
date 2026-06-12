<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();

            // Strict relationship to the internal product container
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            // Loose relationship pointing directly to the Media module's ledger
            $table->unsignedBigInteger('media_id');

            // Allows the admin to arrange the thumbnail gallery sequence order
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
