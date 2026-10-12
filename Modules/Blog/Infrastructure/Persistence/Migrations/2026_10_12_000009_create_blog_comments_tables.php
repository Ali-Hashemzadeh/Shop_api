<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blog discussion: threaded comments (public code `bdm-XXXXXX`), their Media
 * attachments (loose), reports, and reactions. `user_id`, `reported_by_user_id`
 * and the moderator columns are loose Identity references.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_comments', function (Blueprint $table) {
            $table->id();
            $table->string('public_code', 16)->nullable()->unique();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            // Same-module self reference. Comments are soft-deleted, so a removed
            // parent keeps its replies attached (thread integrity).
            $table->foreignId('parent_id')->nullable()->constrained('blog_comments')->restrictOnDelete();
            $table->text('body');
            $table->string('status')->default('pending');
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['blog_post_id', 'parent_id', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('blog_comment_media', function (Blueprint $table) {
            $table->foreignId('blog_comment_id')->constrained('blog_comments')->cascadeOnDelete();
            $table->unsignedBigInteger('media_id');
            $table->integer('sort_order')->default(0);

            $table->primary(['blog_comment_id', 'media_id']);
        });

        Schema::create('blog_comment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('blog_comments')->cascadeOnDelete();
            $table->unsignedBigInteger('reported_by_user_id');
            $table->string('reason');
            $table->text('details')->nullable();
            $table->string('status')->default('open');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['comment_id', 'status']);
            $table->index(['reported_by_user_id', 'comment_id', 'status']);
            $table->index('status');
        });

        Schema::create('blog_comment_reactions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->foreignId('comment_id')->constrained('blog_comments')->cascadeOnDelete();
            $table->string('reaction');
            $table->timestamp('created_at')->nullable();

            // One reaction per user per comment; switching replaces it.
            $table->primary(['user_id', 'comment_id']);
            $table->index(['comment_id', 'reaction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_comment_reactions');
        Schema::dropIfExists('blog_comment_reports');
        Schema::dropIfExists('blog_comment_media');
        Schema::dropIfExists('blog_comments');
    }
};
