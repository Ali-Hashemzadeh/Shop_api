<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single AI review draft. `published_review_id` is the loose back-link to the
 * review created on approval (no FK — Review is a separate module).
 * `approved_by` is a loose Identity reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generated_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_id')->constrained('ai_review_generations')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->string('name');
            $table->unsignedTinyInteger('rating');
            $table->string('title')->nullable();
            $table->text('body');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('published_review_id')->nullable();
            $table->timestamps();

            $table->index(['generation_id', 'status']);
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generated_reviews');
    }
};
