<?php

namespace Tests\Feature\ProductReviewAI;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Domain\Models\Product;
use Modules\ProductReviewAI\Application\Support\GenerationLifecycle;
use Modules\ProductReviewAI\Application\Support\StuckGenerationPolicy;
use Modules\ProductReviewAI\Domain\Enums\GenerationStage;
use Modules\ProductReviewAI\Domain\Enums\GenerationStatus;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Modules\ProductReviewAI\Domain\Models\AiReviewGenerationLog;
use Tests\Feature\ProductReviewAI\Concerns\InteractsWithAiReviewPipeline;
use Tests\TestCase;

/**
 * `ai-reviews:detect-stuck-generations` + StuckGenerationPolicy.
 *
 * Thresholds used here: pending 5 min, inactivity 5 min (floor = longest
 * blocking stage 120 s + 60 s), processing timeout 10 min, job timeout ~8 min.
 */
class StuckGenerationDetectionTest extends TestCase
{
    use InteractsWithAiReviewPipeline;
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPipeline();
        $this->product = $this->createProduct();

        config([
            'product_review_ai.sources.digikala.max_pages' => 1,
            'product_review_ai.sources.digikala.timeout' => 30,
            'product_review_ai.ai.providers.avalai.timeout' => 120,
            'product_review_ai.monitoring.pending_threshold_seconds' => 300,
            'product_review_ai.monitoring.inactivity_threshold_seconds' => 300,
            'product_review_ai.monitoring.processing_timeout_seconds' => 600,
            'product_review_ai.monitoring.auto_fail_abandoned' => true,
            'product_review_ai.queue.job_timeout' => 500,
        ]);
    }

    private function processing(int $startedMinutesAgo, int $heartbeatMinutesAgo, string $stage = 'analyzing_reviews'): AiReviewGeneration
    {
        return $this->makeGeneration($this->product, [
            'status' => 'processing',
            'current_stage' => $stage,
            'execution_token' => 'worker:1',
            'created_at' => now()->subMinutes($startedMinutesAgo + 1),
            'started_at' => now()->subMinutes($startedMinutesAgo),
            'last_heartbeat_at' => now()->subMinutes($heartbeatMinutesAgo),
            'updated_at' => now()->subMinutes($heartbeatMinutesAgo),
        ]);
    }

    public function test_fresh_pending_and_processing_generations_are_not_stuck(): void
    {
        $pending = $this->makeGeneration($this->product, ['status' => 'pending']);
        $processing = $this->processing(1, 1);
        $policy = app(StuckGenerationPolicy::class);

        $this->assertFalse($policy->isSuspected($pending));
        $this->assertFalse($policy->isSuspected($processing));

        $this->artisan('ai-reviews:detect-stuck-generations')
            ->expectsOutputToContain('Inspected 2 active generation(s); 0 suspected stuck; 0 reconciled')
            ->assertSuccessful();
    }

    public function test_an_old_pending_generation_is_only_suspected_never_failed(): void
    {
        $pending = $this->makeGeneration($this->product, [
            'status' => 'pending', 'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3),
        ]);

        $this->artisan('ai-reviews:detect-stuck-generations')
            ->expectsOutputToContain('1 suspected stuck; 0 reconciled')
            ->assertSuccessful();

        $this->assertSame(GenerationStatus::Pending, $pending->refresh()->status);
        $this->assertTrue(app(StuckGenerationPolicy::class)->isSuspected($pending));
    }

    public function test_a_stale_processing_generation_is_reconciled_as_failed(): void
    {
        $stale = $this->processing(20, 20, 'collecting_reviews');

        $this->artisan('ai-reviews:detect-stuck-generations')
            ->expectsOutputToContain('1 suspected stuck; 1 reconciled')
            ->assertSuccessful();

        $stale->refresh();
        $this->assertSame(GenerationStatus::Failed, $stale->status);
        $this->assertSame('PROCESSING_TIMEOUT', $stale->failure_code);
        $this->assertSame('collecting_reviews', $stale->failed_stage);
        $this->assertNotNull($stale->finished_at);
        $this->assertNotNull($stale->duration_ms);
        $this->assertStringContainsString('abandoned', $stale->failure_reason);
        $this->assertTrue(AiReviewGenerationLog::query()->where('generation_id', $stale->id)->where('type', 'STUCK_RECONCILED')->exists());
    }

    public function test_a_long_external_request_is_suspected_but_not_treated_as_abandoned(): void
    {
        // Inactive past the inactivity threshold, but still within the overall timeout.
        $slow = $this->processing(8, 8);

        $this->artisan('ai-reviews:detect-stuck-generations')
            ->expectsOutputToContain('1 suspected stuck; 0 reconciled')
            ->assertSuccessful();

        $this->assertSame(GenerationStatus::Processing, $slow->refresh()->status);
    }

    public function test_the_inactivity_threshold_never_drops_below_the_longest_blocking_http_stage(): void
    {
        config(['product_review_ai.monitoring.inactivity_threshold_seconds' => 30]);
        $policy = app(StuckGenerationPolicy::class);

        $this->assertSame(180, $policy->inactivityThreshold()); // max(30, 120 + 60)

        // 2 minutes inside one AI call is normal, not stuck.
        $this->assertFalse($policy->isSuspected($this->processing(2, 2)));
    }

    public function test_repeated_runs_are_idempotent(): void
    {
        $stale = $this->processing(20, 20);

        $this->artisan('ai-reviews:detect-stuck-generations')->expectsOutputToContain('1 reconciled')->assertSuccessful();
        $finishedAt = $stale->refresh()->finished_at;

        $this->artisan('ai-reviews:detect-stuck-generations')
            ->expectsOutputToContain('Inspected 0 active generation(s); 0 suspected stuck; 0 reconciled')
            ->assertSuccessful();

        $this->assertEquals($finishedAt, $stale->refresh()->finished_at);
        $this->assertSame(1, AiReviewGenerationLog::query()->where('generation_id', $stale->id)->where('type', 'STUCK_RECONCILED')->count());
    }

    public function test_worker_progress_after_inspection_wins_over_reconciliation(): void
    {
        $stale = $this->processing(20, 20);
        $observed = AiReviewGeneration::query()->findOrFail($stale->id);

        // The worker reaches the next stage boundary after the detector read the row.
        app(GenerationLifecycle::class)->enterStage($stale->id, 'worker:1', GenerationStage::GeneratingReviews);

        $this->assertFalse(app(GenerationLifecycle::class)->reconcileAbandoned($observed, 'abandoned'));
        $this->assertSame(GenerationStatus::Processing, $stale->refresh()->status);
    }

    public function test_concurrent_completion_is_never_overwritten(): void
    {
        $stale = $this->processing(20, 20);
        $observed = AiReviewGeneration::query()->findOrFail($stale->id);

        DB::table('ai_review_generations')->where('id', $stale->id)->update(['status' => 'completed', 'current_stage' => 'finished']);

        $this->assertFalse(app(GenerationLifecycle::class)->reconcileAbandoned($observed, 'abandoned'));
        $this->assertSame(GenerationStatus::Completed, $stale->refresh()->status);
    }

    public function test_completed_and_failed_generations_are_not_modified(): void
    {
        $old = now()->subDays(2);
        $completed = $this->makeGeneration($this->product, ['status' => 'completed', 'created_at' => $old, 'updated_at' => $old, 'started_at' => $old]);
        $failed = $this->makeGeneration($this->product, ['status' => 'failed', 'failure_code' => 'AI_TIMEOUT', 'created_at' => $old, 'updated_at' => $old]);

        $this->artisan('ai-reviews:detect-stuck-generations')
            ->expectsOutputToContain('Inspected 0 active generation(s)')
            ->assertSuccessful();

        $this->assertSame(GenerationStatus::Completed, $completed->refresh()->status);
        $this->assertSame('AI_TIMEOUT', $failed->refresh()->failure_code);
        $this->assertFalse(app(StuckGenerationPolicy::class)->isSuspected($completed));
    }

    public function test_no_state_change_when_the_processing_timeout_does_not_exceed_the_job_timeout(): void
    {
        config(['product_review_ai.queue.job_timeout' => 900]); // > processing timeout (600)
        $stale = $this->processing(20, 20);

        $this->artisan('ai-reviews:detect-stuck-generations')
            ->expectsOutputToContain('1 suspected stuck; 0 reconciled')
            ->expectsOutputToContain('Automatic failing is off')
            ->assertSuccessful();

        $this->assertSame(GenerationStatus::Processing, $stale->refresh()->status);
    }

    public function test_dry_run_reports_without_changing_state(): void
    {
        $stale = $this->processing(20, 20);

        $this->artisan('ai-reviews:detect-stuck-generations', ['--dry-run' => true])
            ->expectsOutputToContain('1 suspected stuck; 0 reconciled')
            ->assertSuccessful();

        $this->assertSame(GenerationStatus::Processing, $stale->refresh()->status);
    }

    public function test_a_historical_processing_row_without_timestamps_is_judged_by_its_real_updated_at(): void
    {
        $legacy = $this->makeGeneration($this->product, [
            'status' => 'processing', 'created_at' => now()->subDays(1), 'updated_at' => now()->subDays(1),
        ]);

        $this->assertTrue(app(StuckGenerationPolicy::class)->isAbandoned($legacy));
        $this->artisan('ai-reviews:detect-stuck-generations')->expectsOutputToContain('1 reconciled')->assertSuccessful();

        $legacy->refresh();
        $this->assertSame(GenerationStatus::Failed, $legacy->status);
        $this->assertNull($legacy->started_at); // never fabricated
    }

    public function test_the_command_is_scheduled_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'ai-reviews:detect-stuck-generations'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
    }
}
