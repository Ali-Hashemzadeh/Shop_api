<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw customer reviews collected from a marketplace, tied to a mapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mapping_id')->constrained('external_product_mappings')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('title')->nullable();
            $table->text('body');
            $table->json('raw_data')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();

            $table->index('mapping_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_reviews');
    }
};
