<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer engagement: likes, bookmarks, follow-subscriptions, and the Blog-owned
 * engagement counters (`blog_post_stats`) that back the metric sorts.
 *
 * `blog_post_stats` is a read model maintained inside Blog's own write
 * transactions (likes/bookmarks/comment moderation) plus deduplicated view and
 * product-click tracking. Never faked, never queried by another module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_post_likes', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['user_id', 'blog_post_id']);
            $table->index('blog_post_id');
        });

        Schema::create('blog_post_bookmarks', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['user_id', 'blog_post_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('blog_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('blog_post_stats', function (Blueprint $table) {
            $table->foreignId('blog_post_id')->primary()->constrained('blog_posts')->cascadeOnDelete();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->unsignedBigInteger('likes_count')->default(0);
            $table->unsignedBigInteger('bookmarks_count')->default(0);
            $table->unsignedBigInteger('comments_count')->default(0);
            $table->unsignedBigInteger('product_clicks_count')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->index('views_count');
            $table->index('comments_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_stats');
        Schema::dropIfExists('blog_subscriptions');
        Schema::dropIfExists('blog_post_bookmarks');
        Schema::dropIfExists('blog_post_likes');
    }
};
