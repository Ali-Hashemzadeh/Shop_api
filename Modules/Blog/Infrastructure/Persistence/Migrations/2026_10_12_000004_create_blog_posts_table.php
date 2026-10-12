<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blog articles.
 *
 * - `public_code` (`bdb-XXXXXX`) is server-owned and immutable; nullable at the
 *   DB level like every other public-code column (SQLite table-rebuild reason),
 *   always assigned by the application, backstopped by the unique index.
 * - `content` is sanitized editor HTML (LONGTEXT). There is no content_format,
 *   no Markdown, no block JSON, and no reading_time_minutes.
 * - `search_text` is a derived, Persian-normalized plain-text projection of
 *   title + excerpt + content used only for search; the stored HTML is never
 *   rewritten for search.
 * - Media columns are loose references (no FK). `created_by_user_id` /
 *   `updated_by_user_id` are loose Identity references.
 * - `version` backs optimistic locking (409 on a stale save).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->string('public_code', 16)->nullable()->unique();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable();
            $table->longText('search_text')->nullable();
            $table->unsignedBigInteger('featured_media_id')->nullable();
            $table->unsignedBigInteger('primary_author_id')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('first_published_at')->nullable();
            $table->timestamp('last_published_at')->nullable();
            $table->timestamp('unpublish_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('allow_comments')->default(true);
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->string('og_title')->nullable();
            $table->string('og_description', 500)->nullable();
            $table->unsignedBigInteger('og_media_id')->nullable();
            $table->boolean('robots_index')->default(true);
            $table->boolean('robots_follow')->default(true);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index(['status', 'unpublish_at']);
            $table->index(['is_featured', 'published_at']);
            $table->index('primary_author_id');
            $table->index('created_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
