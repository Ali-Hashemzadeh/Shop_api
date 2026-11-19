<?php

namespace Tests\Feature\ProductReviewAI;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Catalog\Domain\Models\Product;
use Modules\ProductReviewAI\Application\Jobs\GenerateProductAIReviewsJob;
use Modules\ProductReviewAI\Domain\Enums\GenerationStatus;
use Modules\ProductReviewAI\Domain\Exceptions\AiGenerationException;
use Modules\ProductReviewAI\Domain\Models\AiPrompt;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Modules\ProductReviewAI\Domain\Models\AiReviewGenerationLog;
use Modules\ProductReviewAI\Domain\Models\ExternalProductMapping;
use RuntimeException;
use Tests\Feature\ProductReviewAI\Concerns\InteractsWithAiReviewPipeline;
use Tests\TestCase;

/**
 * POST /api/v1/admin/ai-reviews/generations/{generation}/retry
 */
class GenerationRetryTest extends TestCase
{
    use InteractsWithAiReviewPipeline;
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPipeline();
        $this->product = $this->createProduct();
        $this->mapProduct($this->product);
    }

    /** Runs a real generation whose AI stage fails (sync queue) → failed run with a snapshot. */
    private function failedGeneration(): AiReviewGeneration
    {
        $this->bindSource($this->reviews());
        $this->bindAi([new AiGenerationException('AvalAI returned HTTP 500')]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/products/{$this->product->uuid}/ai-reviews/generate", ['count' => 2])->assertStatus(202);

        $generation = AiReviewGeneration::query()->latest('id')->firstOrFail();
        $this->assertSame(GenerationStatus::Failed, $generation->status);

        return $generation;
    }

    private function retry(int $id)
    {
        return $this->postJson("/api/v1/admin/ai-reviews/generations/{$id}/retry");
    }

    public function test_a_failed_generation_is_retried_as_a_new_linked_run(): void
    {
        $original = $this->failedGeneration();
        $originalLogs = AiReviewGenerationLog::query()->where('generation_id', $original->id)->count();
        $this->bindAi([$this->analysisJson(), $this->generationJson()]);

        $response = $this->retry($original->id)
            ->assertStatus(202)
            ->assertJsonPath('retry_of_id', $original->id);

        $retryId = $response->json('id');
        $this->assertNotSame($original->id, $retryId);

        $retry = AiReviewGeneration::query()->findOrFail($retryId);
        $this->assertSame($original->id, $retry->retry_of_id);
        $this->assertSame(GenerationStatus::Completed, $retry->status); // sync queue ran it
        $this->assertSame($original->request_context, $retry->request_context);
        $this->assertSame($original->count_requested, $retry->count_requested);
        $this->assertSame($original->model, $retry->model);
        $this->assertSame($original->prompt_version, $retry->prompt_version);

        // The original and its history are untouched (one RETRY_REQUESTED appended).
        $original->refresh();
        $this->assertSame(GenerationStatus::Failed, $original->status);
        $this->assertSame('AI_GENERATION_FAILED', $original->failure_code);
        $this->assertSame($originalLogs + 1, AiReviewGenerationLog::query()->where('generation_id', $original->id)->count());
        $this->assertTrue(AiReviewGenerationLog::query()->where('generation_id', $original->id)->where('type', 'RETRY_REQUESTED')->exists());

        $this->getJson("/api/v1/admin/ai-reviews/generations/{$original->id}")
            ->assertOk()
            ->assertJsonPath('retry_generation_id', $retryId)
            ->assertJsonPath('can_retry', false);
    }

    public function test_retry_returns_a_pending_run_when_queued(): void
    {
        $original = $this->failedGeneration();
        Queue::fake();

        $this->retry($original->id)
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('current_stage', 'queued')
            ->assertJsonPath('retry_of_id', $original->id);

        Queue::assertPushed(GenerateProductAIReviewsJob::class, 1);
    }

    public function test_retry_reproduces_the_original_configuration_not_the_current_one(): void
    {
        $original = $this->failedGeneration();

        config(['product_review_ai.ai.providers.avalai.model' => 'newer-model']);
        AiPrompt::query()->where('name', 'review_generation')->update(['active' => false]);
        AiPrompt::query()->create(['name' => 'review_generation', 'version' => 2, 'model' => 'x', 'system_prompt' => 'v2', 'user_prompt' => 'v2', 'active' => true]);

        $ai = $this->bindAi([$this->analysisJson(), $this->generationJson()]);

        $id = $this->retry($original->id)->assertStatus(202)->json('id');

        $this->assertSame(['deepseek-v4.1-flash', 'deepseek-v4.1-flash'], array_column($ai->options, 'model'));
        $request = AiReviewGenerationLog::query()->where('generation_id', $id)->where('type', 'AI_GENERATION_REQUEST')->firstOrFail()->request;
        $this->assertSame(1, $request['prompt']['version']);
        $this->assertSame('deepseek-v4.1-flash', AiReviewGeneration::query()->findOrFail($id)->model);
    }

    public function test_completed_pending_and_processing_generations_cannot_be_retried(): void
    {
        $this->actingAsAdmin();

        foreach (['completed', 'pending', 'processing'] as $status) {
            $generation = $this->makeGeneration($this->product, ['status' => $status]);

            $this->retry($generation->id)
                ->assertStatus(409)
                ->assertJsonPath('reason', 'GENERATION_NOT_FAILED')
                ->assertJsonPath('status', $status);
        }

        $this->assertSame(3, AiReviewGeneration::query()->count());
    }

    public function test_a_historical_run_without_a_snapshot_is_not_retried_with_guessed_context(): void
    {
        $this->actingAsAdmin();
        $legacy = $this->makeGeneration($this->product, ['status' => 'failed', 'failure_reason' => 'old failure']);

        $this->retry($legacy->id)
            ->assertStatus(409)
            ->assertJsonPath('reason', 'RETRY_CONTEXT_UNAVAILABLE');

        $this->assertSame(1, AiReviewGeneration::query()->count());
        $this->getJson("/api/v1/admin/ai-reviews/generations/{$legacy->id}")->assertJsonPath('can_retry', false);
    }

    public function test_a_changed_mapping_makes_the_retry_not_reproducible(): void
    {
        $original = $this->failedGeneration();
        ExternalProductMapping::query()->where('product_id', $this->product->id)->update(['external_id' => '999']);

        $this->retry($original->id)
            ->assertStatus(409)
            ->assertJsonPath('reason', 'RETRY_CONTEXT_UNAVAILABLE');
    }

    public function test_a_generation_can_be_retried_only_once_and_the_chain_continues_from_the_latest_attempt(): void
    {
        $original = $this->failedGeneration();
        Queue::fake();

        $firstRetry = $this->retry($original->id)->assertStatus(202)->json('id');

        // Second request while the first retry is still active → 409, no duplicate.
        $this->retry($original->id)
            ->assertStatus(409)
            ->assertJsonPath('reason', 'RETRY_ALREADY_EXISTS')
            ->assertJsonPath('retry_generation_id', $firstRetry)
            ->assertJsonPath('retry_status', 'pending');

        $this->assertSame(1, AiReviewGeneration::query()->where('retry_of_id', $original->id)->count());

        // The retry fails too; it can itself be retried, extending the chain.
        DB::table('ai_review_generations')->where('id', $firstRetry)->update(['status' => 'failed', 'failure_code' => 'AI_TIMEOUT']);

        $this->retry($original->id)->assertStatus(409)->assertJsonPath('retry_status', 'failed');
        $secondRetry = $this->retry($firstRetry)->assertStatus(202)->assertJsonPath('retry_of_id', $firstRetry)->json('id');

        $this->assertNotSame($firstRetry, $secondRetry);
    }

    public function test_the_unique_index_blocks_concurrent_duplicate_retries(): void
    {
        $original = $this->failedGeneration();
        $this->makeGeneration($this->product, ['retry_of_id' => $original->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeGeneration($this->product, ['retry_of_id' => $original->id]);
    }

    public function test_a_retry_that_loses_the_race_gets_a_conflict_not_a_duplicate(): void
    {
        $original = $this->failedGeneration();
        Queue::fake();

        // Simulate a concurrent request committing its retry between our
        // "already retried?" check and our insert.
        AiReviewGeneration::creating(function (AiReviewGeneration $g) use ($original): void {
            if ($g->retry_of_id === $original->id && ! DB::table('ai_review_generations')->where('retry_of_id', $original->id)->exists()) {
                DB::table('ai_review_generations')->insert([
                    'product_id' => $g->product_id, 'model' => 'm', 'count_requested' => 1, 'status' => 'pending',
                    'retry_of_id' => $original->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->retry($original->id)
            ->assertStatus(409)
            ->assertJsonPath('reason', 'RETRY_ALREADY_EXISTS');

        // Our own insert never happened and nothing was queued. (The simulated
        // competitor shares this test's connection, so it rolled back with our
        // transaction; a real competitor commits on its own connection.)
        $this->assertSame(0, AiReviewGeneration::query()->where('retry_of_id', $original->id)->where('model', '!=', 'm')->count());
        Queue::assertNothingPushed();
    }

    public function test_a_dispatch_failure_marks_the_new_run_failed_instead_of_leaving_it_pending(): void
    {
        $original = $this->failedGeneration();

        $bus = \Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('queue backend unavailable'));
        $this->app->instance(Dispatcher::class, $bus);

        $response = $this->retry($original->id)->assertStatus(503);
        $retryId = $response->json('generation_id');

        $retry = AiReviewGeneration::query()->findOrFail($retryId);
        $this->assertSame(GenerationStatus::Failed, $retry->status);
        $this->assertSame('QUEUE_PROCESSING_ERROR', $retry->failure_code);
        $this->assertSame('queued', $retry->failed_stage);
        $this->assertSame($original->id, $retry->retry_of_id);
        $this->assertSame(GenerationStatus::Failed, $original->refresh()->status);
        $this->assertSame(0, AiReviewGeneration::query()->where('status', 'pending')->count());
    }

    public function test_retry_permissions_are_enforced(): void
    {
        $original = $this->failedGeneration();

        $this->actingWithPermissions('product-review-ai.manage');
        $this->retry($original->id)->assertStatus(403);

        $this->actingAsCustomer();
        $this->retry($original->id)->assertStatus(403);

        $this->actingWithPermissions('product-review-ai.manage', 'product-review-ai.retry');
        Queue::fake();
        $this->retry($original->id)->assertStatus(202);
    }

    public function test_retry_requires_authentication_and_an_existing_generation(): void
    {
        $this->retry(1)->assertStatus(401);

        $this->actingAsAdmin();
        $this->retry(424242)->assertStatus(404);
    }
}
