<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One record is both a star rating and a comment. There is no separate
 * `comments` table and no Eloquent polymorphic relation: `subject_type` +
 * `subject_id` are a loose reference (no FK), exactly like `media_id` and
 * `user_id` elsewhere in the codebase — consuming modules resolve their own
 * subjects, so Review never imports Catalog's models.
 *
 * The unique index on `(user_id, subject_type, subject_id)` makes one review
 * per user per subject: a commenter who later becomes a verified purchaser
 * edits their existing row to add a rating and photos instead of getting a
 * second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            // Public code (`bdr-XXXXXX`). Nullable at the DB level for the same
            // SQLite table-rebuild reason as the other public-code columns; the
            // application layer always assigns it and the unique index backstops.
            $table->string('uuid', 16)->nullable()->unique();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            // Loose Identity reference — no FK, no exists:users,id.
            $table->unsignedBigInteger('user_id');
            $table->tinyInteger('rating')->nullable();
            $table->text('body');
            // Pre-uploaded Media ids (loose coupling — no FK into media).
            $table->json('gallery_media_ids')->nullable();
            // Server-computed only, never client-accepted.
            $table->boolean('verified_purchase')->default(false);
            $table->string('status')->default('pending');
            $table->text('seller_reply')->nullable();
            $table->timestamp('seller_reply_at')->nullable();
            $table->timestamps();

            // One review per user per subject.
            $table->unique(['user_id', 'subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
