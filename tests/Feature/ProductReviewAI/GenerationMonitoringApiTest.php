<?php

namespace Tests\Feature\ProductReviewAI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Domain\Models\Product;
use Modules\ProductReviewAI\Domain\Enums\DraftStatus;
use Modules\ProductReviewAI\Domain\Models\AiGeneratedReview;
use Modules\ProductReviewAI\Domain\Models\ReviewSource;
use Tests\Feature\ProductReviewAI\Concerns\InteractsWithAiReviewPipeline;
use Tests\TestCase;

/**
 * GET /api/v1/admin/ai-reviews/generations, /stats and /{generation}, plus
 * backward compatibility of the existing per-product and detail endpoints.
 */
class GenerationMonitoringApiTest extends TestCase
{
    use InteractsWithAiReviewPipeline;
    use RefreshDatabase;

    private const LIST = '/api/v1/admin/ai-reviews/generations';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPipeline();
    }

    private function draft(int $generationId, Product $product, DraftStatus $status = DraftStatus::Pending): void
    {
        AiGeneratedReview::query()->create([
            'generation_id' => $generationId, 'product_id' => $product->id,
            'name' => 'کاربر', 'rating' => 4, 'body' => 'خوب', 'status' => $status->value,
        ]);
    }

    public function test_lists_generations_across_products_with_labels_and_execution_fields(): void
    {
        $phone = $this->createProduct('گوشی');
        $laptop = $this->createProduct('لپ‌تاپ');
        $a = $this->makeGeneration($phone, ['status' => 'completed', 'current_stage' => 'finished', 'duration_ms' => 1500,
            'started_at' => now()->subSeconds(2), 'finished_at' => now(), 'created_by' => 7]);
        $this->draft($a->id, $phone);
        $this->draft($a->id, $phone);
        $this->makeGeneration($laptop, ['status' => 'failed', 'failure_code' => 'AI_TIMEOUT', 'failure_reason' => 'AvalAI timed out', 'failed_stage' => 'analyzing_reviews']);
        $this->actingAsAdmin();

        $response = $this->getJson(self::LIST)->assertOk()->assertJsonCount(2, 'data');

        $rows = collect($response->json('data'))->keyBy('id');
        $completed = $rows[$a->id];
        $this->assertSame($phone->uuid, $completed['product']['uuid']);
        $this->assertSame('گوشی', $completed['product']['title']);
        $this->assertSame('digikala', $completed['source']['code']);
        $this->assertSame(2, $completed['count_generated']);
        $this->assertSame(1500, $completed['duration_ms']);
        $this->assertSame('finished', $completed['current_stage']);
        $this->assertSame(7, $completed['created_by']);
        $this->assertNull($completed['failure']);
        $this->assertFalse($completed['can_retry']);
        $this->assertArrayNotHasKey('analysis', $completed);

        $failed = $rows->firstWhere('status', 'failed');
        $this->assertSame(['code' => 'AI_TIMEOUT', 'message' => 'AvalAI timed out', 'stage' => 'analyzing_reviews', 'retryable' => true], $failed['failure']);
        $this->assertSame($laptop->uuid, $failed['product']['uuid']);
    }

    public function test_listing_is_paginated_with_defaults_and_meta(): void
    {
        $product = $this->createProduct();
        foreach (range(1, 3) as $i) {
            $this->makeGeneration($product);
        }
        $this->actingAsAdmin();

        $this->getJson(self::LIST)->assertOk()->assertJsonPath('meta.per_page', 20)->assertJsonPath('meta.total', 3);

        $this->getJson(self::LIST.'?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_filters_by_status_product_source_creator_model_and_date_range(): void
    {
        $phone = $this->createProduct();
        $laptop = $this->createProduct();
        $other = ReviewSource::query()->create(['code' => 'torob', 'name' => 'Torob', 'driver' => 'torob', 'is_active' => true]);

        $old = $this->makeGeneration($phone, ['status' => 'failed', 'created_at' => '2026-10-01 10:00:00', 'created_by' => 3]);
        $mid = $this->makeGeneration($laptop, ['status' => 'completed', 'created_at' => '2026-11-05 23:30:00', 'model' => 'gpt-x']);
        $new = $this->makeGeneration($phone, ['status' => 'completed', 'created_at' => '2026-11-10 00:00:00', 'source_id' => $other->id]);
        $this->actingAsAdmin();

        $ids = fn (string $query) => collect($this->getJson(self::LIST.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$mid->id, $new->id], $ids('?status=completed'));
        $this->assertSame([$old->id, $new->id], $ids('?product_uuid='.$phone->uuid));
        $this->assertSame([$new->id], $ids('?source_id='.$other->id));
        $this->assertSame([$old->id], $ids('?created_by=3'));
        $this->assertSame([$mid->id], $ids('?model=gpt-x'));
        // Plain dates cover the whole day (UTC): 2026-11-05 includes 23:30.
        $this->assertSame([$mid->id], $ids('?date_from=2026-11-05&date_to=2026-11-05'));
        $this->assertSame([$mid->id, $new->id], $ids('?date_from=2026-11-01'));
        // An offset datetime is converted to UTC: 2026-11-06T03:00+03:30 = 2026-11-05T23:30Z.
        $this->assertSame([$mid->id], $ids('?date_from='.urlencode('2026-11-06T03:00:00+03:30').'&date_to=2026-11-06'));
    }

    public function test_sorting_is_deterministic(): void
    {
        $product = $this->createProduct();
        $sameTime = '2026-11-01 12:00:00';
        $first = $this->makeGeneration($product, ['created_at' => $sameTime, 'status' => 'completed', 'duration_ms' => 500]);
        $second = $this->makeGeneration($product, ['created_at' => $sameTime, 'status' => 'completed', 'duration_ms' => 9000]);
        $unfinished = $this->makeGeneration($product, ['created_at' => '2026-11-02 12:00:00']);
        $this->actingAsAdmin();

        $order = fn (string $sort) => collect($this->getJson(self::LIST.'?sort='.$sort)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$unfinished->id, $second->id, $first->id], $order('newest'));
        $this->assertSame([$first->id, $second->id, $unfinished->id], $order('oldest'));
        $this->assertSame([$second->id, $first->id, $unfinished->id], $order('longest_duration'));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAsAdmin();

        foreach ([
            '?status=running' => 'status',
            '?sort=random' => 'sort',
            '?per_page=101' => 'per_page',
            '?per_page=0' => 'per_page',
            '?page=0' => 'page',
            '?date_from=not-a-date' => 'date_from',
            '?date_from=2026-11-10&date_to=2026-11-01' => 'date_to',
            '?source_id=abc' => 'source_id',
            '?product_uuid=bdp-NOPE99' => 'product_uuid',
        ] as $query => $field) {
            $this->getJson(self::LIST.$query)->assertStatus(422)->assertJsonValidationErrors($field);
        }
    }

    public function test_monitoring_endpoints_require_authentication_and_permission(): void
    {
        $generation = $this->makeGeneration($this->createProduct());
        $urls = [self::LIST, self::LIST.'/stats', self::LIST.'/'.$generation->id, self::LIST.'/'.$generation->id.'/logs'];

        foreach ($urls as $url) {
            $this->getJson($url)->assertStatus(401);
        }

        $this->actingAsCustomer();
        foreach ($urls as $url) {
            $this->getJson($url)->assertStatus(403);
        }

        $this->actingWithPermissions('product-review-ai.manage');
        foreach ($urls as $url) {
            $this->getJson($url)->assertOk();
        }
    }

    public function test_the_list_does_not_issue_per_row_queries(): void
    {
        $this->actingAsAdmin();
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::LIST)->assertOk();

            return count(DB::getQueryLog());
        };

        $this->makeGeneration($this->createProduct());
        $count(); // warm-up: first request also loads the user's roles/permissions
        $one = $count();

        foreach (range(1, 8) as $i) {
            $this->makeGeneration($this->createProduct());
        }

        $this->assertSame($one, $count());
    }

    public function test_detail_returns_execution_metadata_and_draft_moderation_counts(): void
    {
        $product = $this->createProduct();
        $generation = $this->makeGeneration($product, [
            'status' => 'completed', 'current_stage' => 'finished',
            'started_at' => '2026-11-11 08:00:03', 'finished_at' => '2026-11-11 08:00:25', 'duration_ms' => 22000,
            'last_heartbeat_at' => '2026-11-11 08:00:25', 'attempt_count' => 1, 'prompt_name' => 'review_generation', 'prompt_version' => 1,
        ]);
        $this->draft($generation->id, $product, DraftStatus::Pending);
        $this->draft($generation->id, $product, DraftStatus::Approved);
        $this->actingAsAdmin();

        $this->getJson(self::LIST.'/'.$generation->id)
            ->assertOk()
            ->assertJsonPath('id', $generation->id)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('current_stage', 'finished')
            ->assertJsonPath('product.uuid', $product->uuid)
            ->assertJsonPath('started_at', '2026-11-11T08:00:03.000000Z')
            ->assertJsonPath('finished_at', '2026-11-11T08:00:25.000000Z')
            ->assertJsonPath('duration_ms', 22000)
            ->assertJsonPath('attempt_count', 1)
            ->assertJsonPath('count_generated', 2)
            ->assertJsonPath('drafts.pending', 1)
            ->assertJsonPath('drafts.approved', 1)
            ->assertJsonPath('drafts.rejected', 0)
            ->assertJsonPath('failure', null)
            ->assertJsonPath('is_suspected_stuck', false);
    }

    public function test_a_historical_generation_reports_null_for_unrecorded_metadata(): void
    {
        $product = $this->createProduct();
        // Exactly what a pre-migration row looks like.
        $legacy = $this->makeGeneration($product, ['status' => 'failed', 'failure_reason' => 'Digikala request failed']);
        $this->actingAsAdmin();

        $this->getJson(self::LIST.'/'.$legacy->id)
            ->assertOk()
            ->assertJsonPath('current_stage', null)
            ->assertJsonPath('started_at', null)
            ->assertJsonPath('finished_at', null)
            ->assertJsonPath('duration_ms', null)
            ->assertJsonPath('last_heartbeat_at', null)
            ->assertJsonPath('retry_of_id', null)
            ->assertJsonPath('failure.code', null)
            ->assertJsonPath('failure.message', 'Digikala request failed')
            ->assertJsonPath('failure.retryable', null)
            ->assertJsonPath('can_retry', false);
    }

    public function test_detail_handles_a_deleted_product_and_a_missing_generation(): void
    {
        $product = $this->createProduct();
        $generation = $this->makeGeneration($product);
        DB::table('products')->where('id', $product->id)->delete();
        $this->actingAsAdmin();

        $this->getJson(self::LIST.'/'.$generation->id)
            ->assertOk()
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonPath('product.uuid', null)
            ->assertJsonPath('product.title', null);

        $this->getJson(self::LIST.'/999999')->assertStatus(404);
        $this->getJson(self::LIST.'/abc')->assertStatus(404);
    }

    public function test_stats_are_accurate(): void
    {
        $product = $this->createProduct();
        $this->makeGeneration($product, ['status' => 'completed', 'duration_ms' => 1000]);
        $this->makeGeneration($product, ['status' => 'completed', 'duration_ms' => 3000]);
        $this->makeGeneration($product, ['status' => 'completed']); // historical: no duration
        $this->makeGeneration($product, ['status' => 'failed']);
        $this->makeGeneration($product, ['status' => 'pending', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]); // stuck
        $this->makeGeneration($product, ['status' => 'processing', 'started_at' => now(), 'last_heartbeat_at' => now()]);
        $this->actingAsAdmin();

        $this->getJson(self::LIST.'/stats')
            ->assertOk()
            ->assertJson([
                'total' => 6,
                'pending' => 1,
                'processing' => 1,
                'completed' => 3,
                'failed' => 1,
                'suspected_stuck' => 1,
                'success_rate' => 0.75,
                'average_duration_ms' => 2000,
                'duration_sample_size' => 2,
            ])
            ->assertJsonStructure(['thresholds' => ['pending_threshold_seconds', 'inactivity_threshold_seconds', 'processing_timeout_seconds']]);
    }

    public function test_stats_respect_product_and_date_filters(): void
    {
        $phone = $this->createProduct();
        $laptop = $this->createProduct();
        $this->makeGeneration($phone, ['status' => 'completed', 'created_at' => '2026-11-01 10:00:00']);
        $this->makeGeneration($phone, ['status' => 'failed', 'created_at' => '2026-11-09 10:00:00']);
        $this->makeGeneration($laptop, ['status' => 'failed', 'created_at' => '2026-11-01 10:00:00']);
        $this->actingAsAdmin();

        $this->getJson(self::LIST.'/stats?product_uuid='.$phone->uuid)->assertJson(['total' => 2, 'success_rate' => 0.5]);
        $this->getJson(self::LIST.'/stats?date_to=2026-11-01')->assertJson(['total' => 2, 'completed' => 1, 'failed' => 1]);
        $this->getJson(self::LIST.'/stats?product_uuid=bdp-NOPE99')->assertStatus(422);
    }

    public function test_empty_stats_return_zeros_and_nulls(): void
    {
        $this->actingAsAdmin();

        $this->getJson(self::LIST.'/stats')
            ->assertOk()
            ->assertExactJson([
                'total' => 0, 'pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0,
                'suspected_stuck' => 0, 'success_rate' => null, 'average_duration_ms' => null, 'duration_sample_size' => 0,
                'thresholds' => [
                    'pending_threshold_seconds' => 900,
                    'inactivity_threshold_seconds' => 1200,
                    'processing_timeout_seconds' => 1800,
                ],
            ]);
    }

    public function test_existing_product_scoped_listing_and_detail_remain_compatible(): void
    {
        $product = $this->createProduct();
        $generation = $this->makeGeneration($product, ['status' => 'completed', 'analysis_json' => ['positive_points' => ['x']]]);
        $this->draft($generation->id, $product);
        $this->actingAsAdmin();

        $originalKeys = ['id', 'product_id', 'source_id', 'model', 'count_requested', 'status', 'analysis',
            'prompt_name', 'prompt_version', 'failure_reason', 'created_by', 'created_at'];

        $this->getJson("/api/v1/admin/products/{$product->uuid}/ai-reviews/generations")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonStructure(['data' => [$originalKeys], 'links', 'meta'])
            ->assertJsonPath('data.0.analysis.positive_points.0', 'x');

        $this->getJson("/api/v1/admin/ai-reviews/{$generation->id}")
            ->assertOk()
            ->assertJsonStructure(['generation' => $originalKeys, 'reviews' => [['id', 'name', 'rating', 'body', 'status']]])
            ->assertJsonPath('generation.status', 'completed');
    }
}
