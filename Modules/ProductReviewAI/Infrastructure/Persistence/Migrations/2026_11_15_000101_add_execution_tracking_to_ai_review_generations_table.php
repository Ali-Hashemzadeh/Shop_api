<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Execution tracking for AI generation runs (monitoring, retry, stuck detection).
 *
 * Every column is nullable (or defaulted) so historical runs stay valid exactly
 * as they were: nothing is backfilled, no started/finished/heartbeat time is
 * fabricated, and no historical status is touched. The APIs report `null` for
 * metadata an old run never recorded.
 *
 * - started_at / finished_at / duration_ms — recorded by the worker; duration is
 *   stored (not computed in SQL) so sorting/averaging is portable and indexable.
 * - current_stage / failed_stage — GenerationStage values.
 * - failure_code — GenerationFailureCode value (structured, alongside the
 *   existing free-text failure_reason).
 * - last_heartbeat_at — last durable progress write at a stage boundary.
 * - attempt_count — queue deliveries that reached the worker for this run.
 * - execution_token — identifies the delivery that claimed the run, so a late
 *   or duplicate delivery can never write over it.
 * - queue_job_uuid — Laravel's driver-independent payload uuid (correlation only).
 * - retry_of_id — the failed run this one retries. UNIQUE: a run is retried at
 *   most once, so a retry chain is linear and concurrent retry requests collide
 *   on the index instead of creating two active retries.
 * - request_context — immutable snapshot of what the run was asked to do, so a
 *   retry reproduces the original request instead of today's configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_review_generations', function (Blueprint $table) {
            $table->string('current_stage', 32)->nullable()->after('status');
            $table->string('failure_code', 64)->nullable()->after('failure_reason');
            $table->string('failed_stage', 32)->nullable()->after('failure_code');
            $table->timestamp('started_at')->nullable()->after('created_by');
            $table->timestamp('finished_at')->nullable()->after('started_at');
            $table->unsignedBigInteger('duration_ms')->nullable()->after('finished_at');
            $table->timestamp('last_heartbeat_at')->nullable()->after('duration_ms');
            $table->unsignedInteger('attempt_count')->default(0)->after('last_heartbeat_at');
            $table->string('execution_token', 100)->nullable()->after('attempt_count');
            $table->string('queue_job_uuid', 64)->nullable()->after('execution_token');
            $table->unsignedBigInteger('retry_of_id')->nullable()->after('queue_job_uuid');
            $table->json('request_context')->nullable()->after('retry_of_id');

            $table->unique('retry_of_id', 'ai_review_generations_retry_of_id_unique');
            $table->index(['status', 'created_at'], 'ai_review_generations_status_created_at_index');
            $table->index('created_at', 'ai_review_generations_created_at_index');
            $table->index(['product_id', 'created_at'], 'ai_review_generations_product_created_at_index');
        });

        Schema::table('ai_review_generation_logs', function (Blueprint $table) {
            $table->index(['generation_id', 'created_at', 'id'], 'ai_review_generation_logs_generation_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_review_generation_logs', function (Blueprint $table) {
            $table->dropIndex('ai_review_generation_logs_generation_created_index');
        });

        Schema::table('ai_review_generations', function (Blueprint $table) {
            $table->dropUnique('ai_review_generations_retry_of_id_unique');
            $table->dropIndex('ai_review_generations_status_created_at_index');
            $table->dropIndex('ai_review_generations_created_at_index');
            $table->dropIndex('ai_review_generations_product_created_at_index');

            $table->dropColumn([
                'current_stage',
                'failure_code',
                'failed_stage',
                'started_at',
                'finished_at',
                'duration_ms',
                'last_heartbeat_at',
                'attempt_count',
                'execution_token',
                'queue_job_uuid',
                'retry_of_id',
                'request_context',
            ]);
        });
    }
};
