<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Curated related posts (same module) and shoppable-article product links.
 * `product_id` is a loose Catalog primitive with no FK, validated and hydrated
 * through CatalogManagerInterface only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_related_posts', function (Blueprint $table) {
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignId('related_blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->integer('sort_order')->default(0);

            $table->primary(['blog_post_id', 'related_blog_post_id']);
        });

        Schema::create('blog_post_products', function (Blueprint $table) {
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->string('relation_type')->default('mentioned');
            $table->integer('sort_order')->default(0);

            $table->primary(['blog_post_id', 'product_id']);
            // Reverse lookup ("articles about this product") stays inside Blog.
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_products');
        Schema::dropIfExists('blog_related_posts');
    }
};
