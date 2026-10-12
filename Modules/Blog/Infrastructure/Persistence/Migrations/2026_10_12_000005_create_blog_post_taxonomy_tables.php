<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-category, post-tag, and post-author pivots (all same-module FKs).
 * Posts are soft-deleted, so the cascades only fire on a hard delete; normal
 * deletion keeps attachments intact for restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_post_category', function (Blueprint $table) {
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignId('blog_category_id')->constrained('blog_categories')->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->integer('sort_order')->default(0);

            $table->primary(['blog_post_id', 'blog_category_id']);
            $table->index('blog_category_id');
        });

        Schema::create('blog_post_tag', function (Blueprint $table) {
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignId('blog_tag_id')->constrained('blog_tags')->cascadeOnDelete();

            $table->primary(['blog_post_id', 'blog_tag_id']);
            $table->index('blog_tag_id');
        });

        Schema::create('blog_post_author', function (Blueprint $table) {
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignId('blog_author_id')->constrained('blog_authors')->restrictOnDelete();
            $table->string('role')->default('author');
            $table->integer('sort_order')->default(0);

            $table->primary(['blog_post_id', 'blog_author_id']);
            $table->index('blog_author_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_author');
        Schema::dropIfExists('blog_post_tag');
        Schema::dropIfExists('blog_post_category');
    }
};
