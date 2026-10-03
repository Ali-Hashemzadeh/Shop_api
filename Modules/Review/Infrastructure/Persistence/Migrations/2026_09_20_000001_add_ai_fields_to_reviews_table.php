<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive support for AI-generated reviews (the ProductReviewAI module).
 *
 * An approved AI draft is published as an ordinary review so the storefront
 * renders it exactly like any other — no separate "AI reviews" section. Because
 * there is deliberately NO fake user account behind it (see ProductReviewAI),
 * such a review has no `user_id`; its display persona lives in `author_name`.
 *
 *   - `user_id` becomes nullable. NULLs are distinct in the composite unique
 *     index, so many AI reviews may attach to one product while the real
 *     one-review-per-user rule still holds for authored reviews.
 *   - `author_name` / `title` are null for authored reviews (the frontend
 *     resolves the customer's name itself and renders no title today) and carry
 *     the AI persona name + headline for published AI reviews.
 *   - `is_ai_generated` + `ai_generation_id` are backend-only provenance so an
 *     AI review stays auditable (which generation created it) without ever being
 *     surfaced as a distinct kind of review to customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nullability change is isolated in its own statement so the SQLite
        // table rebuild it triggers does not interfere with the added columns.
        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->string('author_name')->nullable()->after('user_id');
            $table->string('title')->nullable()->after('rating');
            $table->boolean('is_ai_generated')->default(false)->after('status');
            // Loose reference into the ProductReviewAI module (no FK — same
            // cross-module discipline as media_id / subject_id).
            $table->unsignedBigInteger('ai_generation_id')->nullable()->after('is_ai_generated');
            $table->index(['is_ai_generated', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['is_ai_generated', 'subject_type', 'subject_id']);
            $table->dropColumn(['author_name', 'title', 'is_ai_generated', 'ai_generation_id']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
