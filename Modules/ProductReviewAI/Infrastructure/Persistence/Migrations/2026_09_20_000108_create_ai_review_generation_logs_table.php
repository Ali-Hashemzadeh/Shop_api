<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only debugging ledger: every external/AI request+response and every
 * moderation decision, success or failure, for a generation run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_review_generation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_id')->constrained('ai_review_generations')->cascadeOnDelete();
            $table->string('type');
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->string('status')->default('success');
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['generation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_review_generation_logs');
    }
};
