<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editorial curation: collections (curated groupings such as "Editor's Picks")
 * and keyed placements (homepage/category slots). Placement keys are data, so a
 * new slot never needs a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_collections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('blog_collection_posts', function (Blueprint $table) {
            $table->foreignId('blog_collection_id')->constrained('blog_collections')->cascadeOnDelete();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->integer('sort_order')->default(0);

            $table->primary(['blog_collection_id', 'blog_post_id']);
        });

        Schema::create('blog_placements', function (Blueprint $table) {
            $table->id();
            $table->string('placement_key', 100);
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['placement_key', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_placements');
        Schema::dropIfExists('blog_collection_posts');
        Schema::dropIfExists('blog_collections');
    }
};
