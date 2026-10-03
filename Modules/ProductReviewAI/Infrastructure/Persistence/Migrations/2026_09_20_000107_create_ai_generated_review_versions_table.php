<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only edit history for a draft: one JSON snapshot per change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generated_review_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generated_review_id')->constrained('ai_generated_reviews')->cascadeOnDelete();
            $table->json('content');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('generated_review_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generated_review_versions');
    }
};
