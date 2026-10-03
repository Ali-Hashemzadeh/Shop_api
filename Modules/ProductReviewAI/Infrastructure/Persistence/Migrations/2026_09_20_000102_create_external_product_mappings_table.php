<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal product ↔ external marketplace product. `product_id` is a loose
 * cross-module reference to Catalog's integer product id — no FK (Catalog is a
 * black box). `source_id` is an in-module FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_product_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->foreignId('source_id')->constrained('review_sources')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('external_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // One mapping per product per source; re-mapping updates in place.
            $table->unique(['product_id', 'source_id']);
            $table->index(['source_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_product_mappings');
    }
};
