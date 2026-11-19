<?php

namespace Tests\Feature\ProductReviewAI;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\ProductReviewAI\Application\Actions\ProcessGenerationAction;
use Modules\ProductReviewAI\Application\Actions\RecordJobFailureAction;
use Modules\ProductReviewAI\Application\Jobs\GenerateProductAIReviewsJob;
use Modules\ProductReviewAI\Application\Support\DiagnosticSanitizer;
use Modules\ProductReviewAI\Application\Support\GenerationLifecycle;
use Modules\ProductReviewAI\Domain\Enums\GenerationFailureCode;
use Modules\ProductReviewAI\Domain\Enums\GenerationStatus;
use Modules\ProductReviewAI\Domain\Exceptions\AiGenerationException;
use Modules\ProductReviewAI\Domain\Exceptions\ExternalSourceException;
use Modules\ProductReviewAI\Domain\Exceptions\IntegrationUnavailableException;
use Modules\ProductReviewAI\Domain\Models\AiGeneratedReview;
use Modules\ProductReviewAI\Domain\Models\AiPrompt;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Modules\ProductReviewAI\Domain\Models\AiReviewGenerationLog;
use Modules\ProductReviewAI\Infrastructure\Sources\DigikalaSource;
use PDOException;
use RuntimeException;
use Tests\Feature\ProductReviewAI\Concerns\InteractsWithAiReviewPipeline;
use Tests\TestCase;

/**
 * Execution lifecycle of GenerateProductAIReviewsJob / ProcessGenerationAction:
 * atomic claim, stage tracking, structured failures, duplicate deliveries, and
 * the job-level failed() handler.
 */
class GenerationLifecycleTest extends TestCase
{
    use InteractsWithAiReviewPipeline;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPipeline();
    }

    private function requestGeneration(int $count = 2): AiReviewGeneration
    {
        $product = $this->createProduct();
        $this->mapProduct($product);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/products/{$product->uuid}/ai-reviews/generate", ['count' => $count])
            ->assertStatus(202);

        return AiReviewGeneration::query()->latest('id')->firstOrFail();
    }

    private function process(int $generationId, ?string $token = null): void
    {
        app(ProcessGenerationAction::class)->handle($generationId, [], $token);
    }

    private function stageOf(int $generationId): ?string
    {
        return DB::table('ai_review_generations')->where('id', $generationId)->value('current_stage');
    }

    public function test_new_generation_begins_pending_and_queued_with_a_request_snapshot(): void
    {
        Queue::fake();

        $generation = $this->requestGeneration();

        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame('queued', $generation->current_stage);
        $this->assertNull($generation->started_at);
        $this->assertNull($generation->finished_at);
        $this->assertSame(0, $generation->attempt_count);
        $this->assertSame(1, $generation->request_context['version']);
        $this->assertSame('123', $generation->request_context['mapping']['external_id']);
        $this->assertSame(1, $generation->request_context['prompts']['analysis']['version']);
        $this->assertSame('deepseek-v4.1-flash', $generation->request_context['ai']['model']);

        Queue::assertPushed(GenerateProductAIReviewsJob::class, fn ($job) => $job->generationId === $generation->id
            && $job->tries === 1
            && $job->timeout === (int) config('product_review_ai.queue.job_timeout'));
    }

    public function test_only_one_worker_can_claim_a_pending_generation(): void
    {
        $product = $this->createProduct();
        $generation = $this->makeGeneration($product);
        $lifecycle = app(GenerationLifecycle::class);

        $first = $lifecycle->claim($generation->id, 'worker-a');
        $second = $lifecycle->claim($generation->id, 'worker-b');

        $this->assertNotNull($first);
        $this->assertNull($second);

        $row = DB::table('ai_review_generations')->where('id', $generation->id)->first();
        $this->assertSame('processing', $row->status);
        $this->assertSame('worker-a', $row->execution_token);
        $this->assertSame('initializing', $row->current_stage);
        $this->assertNotNull($row->started_at);
        $this->assertNotNull($row->last_heartbeat_at);
    }

    public function test_stage_transitions_are_recorded_in_order_and_completion_is_timed(): void
    {
        Queue::fake();
        $generation = $this->requestGeneration();
        $seen = [];

        $this->bindSource($this->reviews(), function () use ($generation, &$seen): void {
            $seen[] = $this->stageOf($generation->id);
        });
        $this->bindAi([
            function () use ($generation, &$seen) {
                $seen[] = $this->stageOf($generation->id);

                return $this->analysisJson();
            },
            function () use ($generation, &$seen) {
                $seen[] = $this->stageOf($generation->id);

                return $this->generationJson();
            },
        ]);

        $this->process($generation->id);

        $this->assertSame(['collecting_reviews', 'analyzing_reviews', 'generating_reviews'], $seen);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Completed, $generation->status);
        $this->assertSame('finished', $generation->current_stage);
        $this->assertNotNull($generation->started_at);
        $this->assertNotNull($generation->finished_at);
        $this->assertNotNull($generation->duration_ms);
        $this->assertGreaterThanOrEqual(0, $generation->duration_ms);
        $this->assertNotNull($generation->last_heartbeat_at);
        $this->assertNull($generation->failure_code);
        $this->assertSame(1, $generation->attempt_count);
        $this->assertSame(2, AiGeneratedReview::query()->where('generation_id', $generation->id)->count());

        $types = AiReviewGenerationLog::query()->where('generation_id', $generation->id)->orderBy('id')->pluck('type')->map->value->all();
        $this->assertSame('PROCESSING_STARTED', $types[0]);
        $this->assertSame('PROCESSING_COMPLETED', end($types));
    }

    public function test_source_failures_are_recorded_with_code_and_stage(): void
    {
        Queue::fake();
        $generation = $this->requestGeneration();
        $this->bindSource(new ExternalSourceException('Digikala request to [/x] returned HTTP 503.', 0, null, GenerationFailureCode::SourceRequestFailed));
        $this->bindAi([$this->analysisJson()]);

        $this->process($generation->id);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('SOURCE_REQUEST_FAILED', $generation->failure_code);
        $this->assertSame('collecting_reviews', $generation->failed_stage);
        $this->assertNotNull($generation->finished_at);
        $this->assertStringContainsString('HTTP 503', $generation->failure_reason);
        $this->assertTrue(AiReviewGenerationLog::query()->where('generation_id', $generation->id)->where('type', 'PROCESSING_FAILED')->exists());
    }

    public function test_a_real_digikala_timeout_is_classified_from_the_curl_errno(): void
    {
        Queue::fake();
        $generation = $this->requestGeneration();
        $this->app->forgetInstance(DigikalaSource::class);
        $this->bindAi([$this->analysisJson()]);

        Http::fake(function () {
            throw new ConnectionException('cURL error 28', 0, new ConnectException(
                'Operation timed out',
                new Request('GET', 'https://api.digikala.com'),
                null,
                ['errno' => 28],
            ));
        });

        $this->process($generation->id);

        $generation->refresh();
        $this->assertSame('SOURCE_TIMEOUT', $generation->failure_code);
        $this->assertSame('collecting_reviews', $generation->failed_stage);
    }

    public function test_missing_source_configuration_and_empty_reviews_have_distinct_codes(): void
    {
        Queue::fake();
        $first = $this->requestGeneration();
        $this->bindSource(new IntegrationUnavailableException('Digikala is not configured.'));
        $this->process($first->id);
        $this->assertSame('SOURCE_CONFIGURATION_ERROR', $first->refresh()->failure_code);

        $second = $this->requestGeneration();
        $this->bindSource([]);
        $this->process($second->id);
        $this->assertSame('SOURCE_NO_REVIEWS', $second->refresh()->failure_code);
    }

    public function test_ai_provider_failures_are_recorded(): void
    {
        Queue::fake();
        $this->bindSource($this->reviews());

        $rateLimited = $this->requestGeneration();
        $this->bindAi([new AiGenerationException('AvalAI returned HTTP 429', 0, null, GenerationFailureCode::AiRateLimited)]);
        $this->process($rateLimited->id);
        $rateLimited->refresh();
        $this->assertSame('AI_RATE_LIMITED', $rateLimited->failure_code);
        $this->assertSame('analyzing_reviews', $rateLimited->failed_stage);

        $unconfigured = $this->requestGeneration();
        $this->bindAi([new IntegrationUnavailableException('AvalAI is not configured: set AVALAI_API_KEY.')]);
        $this->process($unconfigured->id);
        $this->assertSame('AI_CONFIGURATION_ERROR', $unconfigured->refresh()->failure_code);

        $generic = $this->requestGeneration();
        $this->bindAi([$this->analysisJson(), new AiGenerationException('AvalAI returned HTTP 500')]);
        $this->process($generic->id);
        $generic->refresh();
        $this->assertSame('AI_GENERATION_FAILED', $generic->failure_code);
        $this->assertSame('generating_reviews', $generic->failed_stage);
    }

    public function test_invalid_ai_responses_are_recorded(): void
    {
        Queue::fake();
        $this->bindSource($this->reviews());

        $notJson = $this->requestGeneration();
        $this->bindAi(['this is not json at all']);
        $this->process($notJson->id);
        $this->assertSame('AI_RESPONSE_INVALID', $notJson->refresh()->failure_code);

        $noReviews = $this->requestGeneration();
        $this->bindAi([$this->analysisJson(), json_encode(['reviews' => [['name' => '', 'rating' => 9]]])]);
        $this->process($noReviews->id);
        $noReviews->refresh();
        $this->assertSame('AI_RESPONSE_INVALID', $noReviews->failure_code);
        $this->assertSame(0, AiGeneratedReview::query()->where('generation_id', $noReviews->id)->count());
    }

    public function test_database_failures_are_recorded_without_leaking_sql(): void
    {
        Queue::fake();
        $generation = $this->requestGeneration();
        $this->bindSource(new QueryException('sqlite', 'insert into secrets (body) values (?)', ['customer@example.com'], new PDOException('disk I/O error', 10)));

        $this->process($generation->id);

        $generation->refresh();
        $this->assertSame('DATABASE_ERROR', $generation->failure_code);
        $this->assertStringNotContainsString('insert into', $generation->failure_reason);
        $this->assertStringNotContainsString('customer@example.com', $generation->failure_reason);
    }

    public function test_duplicate_delivery_does_not_run_the_pipeline_twice_or_duplicate_drafts(): void
    {
        Queue::fake();
        $generation = $this->requestGeneration();
        $source = $this->bindSource($this->reviews());
        $ai = $this->bindAi([$this->analysisJson(), $this->generationJson()]);

        $this->process($generation->id, 'delivery-1');
        $this->process($generation->id, 'delivery-2');

        $this->assertSame(1, $source->calls);
        $this->assertSame(2, $ai->calls);
        $this->assertSame(2, AiGeneratedReview::query()->where('generation_id', $generation->id)->count());

        $generation->refresh();
        $this->assertSame(GenerationStatus::Completed, $generation->status);
        $this->assertSame(2, $generation->attempt_count);
        $this->assertTrue(AiReviewGenerationLog::query()->where('generation_id', $generation->id)->where('type', 'PROCESSING_SKIPPED')->exists());
    }

    public function test_completed_and_failed_generations_are_never_reprocessed(): void
    {
        $product = $this->createProduct();
        $completed = $this->makeGeneration($product, ['status' => 'completed', 'current_stage' => 'finished']);
        $failed = $this->makeGeneration($product, ['status' => 'failed', 'failure_code' => 'AI_TIMEOUT', 'failure_reason' => 'x']);
        $source = $this->bindSource($this->reviews());

        $this->process($completed->id);
        $this->process($failed->id);

        $this->assertSame(0, $source->calls);
        $this->assertSame(GenerationStatus::Completed, $completed->refresh()->status);
        $this->assertSame(GenerationStatus::Failed, $failed->refresh()->status);
        $this->assertSame('AI_TIMEOUT', $failed->failure_code);
    }

    public function test_a_late_worker_cannot_complete_a_run_that_was_failed_meanwhile(): void
    {
        Queue::fake();
        $generation = $this->requestGeneration();
        $this->bindSource($this->reviews());
        $this->bindAi([
            $this->analysisJson(),
            function () use ($generation) {
                // The stuck detector reconciles the run while the worker waits on AI.
                DB::table('ai_review_generations')->where('id', $generation->id)->update([
                    'status' => 'failed', 'failure_code' => 'PROCESSING_TIMEOUT', 'failure_reason' => 'abandoned',
                ]);

                return $this->generationJson();
            },
        ]);

        $this->process($generation->id);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('PROCESSING_TIMEOUT', $generation->failure_code);
        $this->assertSame(0, AiGeneratedReview::query()->where('generation_id', $generation->id)->count());
    }

    public function test_failed_handler_fails_an_unclaimed_generation(): void
    {
        $generation = $this->makeGeneration($this->createProduct(), ['current_stage' => 'queued']);

        (new GenerateProductAIReviewsJob($generation->id))->failed(new RuntimeException('worker crashed before claiming'));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('QUEUE_PROCESSING_ERROR', $generation->failure_code);
        $this->assertSame('queued', $generation->failed_stage);
        $this->assertTrue(AiReviewGenerationLog::query()->where('generation_id', $generation->id)->where('type', 'PROCESSING_FAILED')->exists());
    }

    public function test_failed_handler_records_a_worker_timeout_for_its_own_claim(): void
    {
        $generation = $this->makeGeneration($this->createProduct(), [
            'status' => 'processing', 'current_stage' => 'analyzing_reviews',
            'execution_token' => 'job-uuid:1', 'started_at' => now()->subMinutes(5),
        ]);

        $job = new GenerateProductAIReviewsJob($generation->id);
        $job->setJob($this->queueJob('job-uuid', 1));
        $job->failed(new TimeoutExceededException('timed out'));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('PROCESSING_TIMEOUT', $generation->failure_code);
        $this->assertSame('analyzing_reviews', $generation->failed_stage);
        $this->assertNotNull($generation->duration_ms);
    }

    public function test_a_redelivered_copy_never_fails_a_run_another_worker_owns(): void
    {
        $generation = $this->makeGeneration($this->createProduct(), [
            'status' => 'processing', 'current_stage' => 'collecting_reviews', 'execution_token' => 'job-uuid:1',
        ]);

        $job = new GenerateProductAIReviewsJob($generation->id);
        $job->setJob($this->queueJob('job-uuid', 2));
        $job->failed(new MaxAttemptsExceededException('attempted too many times'));

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
        $this->assertTrue(AiReviewGenerationLog::query()->where('generation_id', $generation->id)->where('type', 'PROCESSING_SKIPPED')->exists());
    }

    public function test_failed_handler_never_overwrites_a_completed_generation(): void
    {
        $generation = $this->makeGeneration($this->createProduct(), [
            'status' => 'completed', 'current_stage' => 'finished', 'execution_token' => 'job-uuid:1',
        ]);

        $job = new GenerateProductAIReviewsJob($generation->id);
        $job->setJob($this->queueJob('job-uuid', 1));
        $job->failed(new RuntimeException('a duplicate blew up'));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Completed, $generation->status);
        $this->assertNull($generation->failure_code);
    }

    public function test_failed_handler_swallows_secondary_errors(): void
    {
        $this->app->bind(RecordJobFailureAction::class, fn () => throw new RuntimeException('db down'));

        (new GenerateProductAIReviewsJob(999))->failed(new RuntimeException('original'));

        $this->assertTrue(true); // reaching here = no exception escaped
    }

    public function test_a_failure_recording_error_is_rethrown_for_the_failed_handler(): void
    {
        Queue::fake();
        $generation = $this->requestGeneration();
        $this->bindSource(new ExternalSourceException('boom'));

        $lifecycle = Mockery::mock(GenerationLifecycle::class, [app(DiagnosticSanitizer::class)])->makePartial();
        $lifecycle->shouldReceive('fail')->once()->andThrow(new RuntimeException('database went away'));
        $this->app->instance(GenerationLifecycle::class, $lifecycle);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database went away');

        $this->process($generation->id);
    }

    public function test_the_pipeline_uses_the_snapshot_model_and_pinned_prompt_version(): void
    {
        Queue::fake();
        $generation = $this->requestGeneration();

        // Configuration drifts after the request was made.
        config(['product_review_ai.ai.providers.avalai.model' => 'some-newer-model']);
        AiPrompt::query()->where('name', 'review_analysis')->update(['active' => false]);
        AiPrompt::query()->create([
            'name' => 'review_analysis', 'version' => 2, 'model' => 'x',
            'system_prompt' => 'v2', 'user_prompt' => 'v2', 'active' => true,
        ]);

        $this->bindSource($this->reviews());
        $ai = $this->bindAi([$this->analysisJson(), $this->generationJson()]);

        $this->process($generation->id);

        $this->assertSame(GenerationStatus::Completed, $generation->refresh()->status);
        $this->assertSame('deepseek-v4.1-flash', $ai->options[0]['model']);
        $request = AiReviewGenerationLog::query()->where('generation_id', $generation->id)->where('type', 'AI_ANALYSIS_REQUEST')->firstOrFail()->request;
        $this->assertSame(1, $request['prompt']['version']);
    }

    private function queueJob(string $uuid, int $attempts): JobContract
    {
        $job = Mockery::mock(JobContract::class);
        $job->shouldReceive('uuid')->andReturn($uuid);
        $job->shouldReceive('attempts')->andReturn($attempts);

        return $job;
    }
}
