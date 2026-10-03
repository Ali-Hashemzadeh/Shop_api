<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One AI generation run. Stores the model + prompt version used (immutable
 * history), the stage-1 analysis, and a failure reason for the admin. Runs are
 * never deleted — the row is the audit trail. `product_id` / `created_by` are
 * loose cross-module references (no FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_review_generations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->foreignId('source_id')->nullable()->constrained('review_sources')->nullOnDelete();
            $table->string('model');
            $table->unsignedInteger('count_requested');
            $table->string('status')->default('pending');
            $table->json('analysis_json')->nullable();
            $table->string('prompt_name')->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_review_generations');
    }
};
