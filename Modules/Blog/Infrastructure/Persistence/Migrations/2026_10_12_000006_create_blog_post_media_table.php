<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media used by an article (inline / gallery / attachment) with article-specific
 * alt text and caption. `media_id` is a loose reference with no FK to `media`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->unsignedBigInteger('media_id');
            $table->string('role')->default('inline');
            $table->string('alt_text')->nullable();
            $table->string('caption', 1000)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['blog_post_id', 'role', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_media');
    }
};
