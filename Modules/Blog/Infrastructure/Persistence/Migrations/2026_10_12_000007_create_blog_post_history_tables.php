<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only editorial history: revisions, activity, slug history, editorial
 * notes, plus the replaceable autosave scratch rows.
 *
 * History tables use `restrictOnDelete`: posts are only ever soft-deleted, and
 * a hard delete must never silently erase revisions, the audit trail, or the
 * redirect history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_post_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('title');
            $table->string('slug');
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable();
            $table->unsignedBigInteger('featured_media_id')->nullable();
            $table->string('status_snapshot');
            $table->json('seo_snapshot')->nullable();
            $table->json('taxonomy_snapshot')->nullable();
            $table->json('author_snapshot')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['blog_post_id', 'revision_number']);
        });

        Schema::create('blog_post_autosaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('title')->nullable();
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable();
            $table->unsignedInteger('base_version');
            $table->timestamps();

            // One scratch row per editor per post: a new autosave replaces it.
            $table->unique(['blog_post_id', 'user_id']);
        });

        Schema::create('blog_post_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->restrictOnDelete();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['blog_post_id', 'id']);
        });

        Schema::create('blog_post_slug_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->restrictOnDelete();
            // Globally unique: a retired slug belongs to exactly one post forever.
            $table->string('slug')->unique();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('blog_editorial_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->restrictOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->text('body');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['blog_post_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_editorial_notes');
        Schema::dropIfExists('blog_post_slug_history');
        Schema::dropIfExists('blog_post_activity');
        Schema::dropIfExists('blog_post_autosaves');
        Schema::dropIfExists('blog_post_revisions');
    }
};
