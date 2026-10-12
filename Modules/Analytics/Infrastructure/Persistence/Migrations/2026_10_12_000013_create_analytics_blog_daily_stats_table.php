<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily Blog engagement per post, built only from Blog's published integration
 * events (deduplicated through analytics_processed_events). `blog_post_id` is a
 * raw id — no FK into Blog, and Analytics never reads Blog tables. `date` is a
 * plain Y-m-d string so equality/upserts behave identically on every driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_blog_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->string('date', 10);
            $table->unsignedBigInteger('blog_post_id');
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('bookmarks')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('product_clicks')->default(0);
            $table->timestamps();

            $table->unique(['date', 'blog_post_id']);
            $table->index('blog_post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_blog_daily_stats');
    }
};
