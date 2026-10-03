<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\ProductReviewAI\Application\Support\SourceResolver;
use Modules\ProductReviewAI\Domain\Enums\DraftStatus;
use Modules\ProductReviewAI\Domain\Enums\GenerationStatus;
use Modules\ProductReviewAI\Domain\Models\AiGeneratedReview;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Modules\ProductReviewAI\Domain\Models\ExternalProductMapping;

/**
 * The full generation pipeline, run inside the queue job:
 *   processing → collect external reviews → AI analysis → AI generation →
 *   save drafts → completed.
 *
 * Every failure (including a missing integration) is caught here: the run is
 * marked `failed` with a reason and the details are already in the generation
 * log. It never rethrows, so a broken integration surfaces as a failed run the
 * admin can see — not a 500 and not fabricated output.
 */
class ProcessGenerationAction
{
    public function __construct(
        private readonly SourceResolver $sources,
        private readonly CollectExternalReviewsAction $collect,
        private readonly RunAnalysisAction $analyze,
        private readonly RunGenerationAction $generate,
    ) {}

    /**
     * @param  array<string, mixed>  $productContext
     */
    public function handle(int $generationId, array $productContext): void
    {
        /** @var AiReviewGeneration|null $generation */
        $generation = AiReviewGeneration::query()->find($generationId);

        if ($generation === null) {
            return;
        }

        $generation->update(['status' => GenerationStatus::Processing->value]);

        try {
            [$source, $adapter] = $this->sources->resolve((string) $generation->source?->code);

            $mapping = ExternalProductMapping::query()
                ->where('product_id', $generation->product_id)
                ->where('source_id', $source->id)
                ->firstOrFail();

            $reviews = $this->collect->handle($generation->id, $mapping, $adapter);

            $analysis = $this->analyze->handle($generation->id, $reviews, $productContext);
            $generation->update(['analysis_json' => $analysis->toArray()]);

            $drafts = $this->generate->handle($generation->id, $productContext, $analysis, (int) $generation->count_requested);

            DB::transaction(function () use ($generation, $drafts): void {
                foreach ($drafts as $draft) {
                    AiGeneratedReview::query()->create([
                        'generation_id' => $generation->id,
                        'product_id' => $generation->product_id,
                        'name' => $draft->name,
                        'rating' => $draft->rating,
                        'title' => $draft->title,
                        'body' => $draft->body,
                        'status' => DraftStatus::Pending->value,
                    ]);
                }

                $generation->update(['status' => GenerationStatus::Completed->value]);
            });
        } catch (\Throwable $e) {
            // The stage action already logged the specifics; record the summary
            // on the run so the admin sees why it failed.
            $generation->update([
                'status' => GenerationStatus::Failed->value,
                'failure_reason' => $e->getMessage(),
            ]);

            Log::warning('ProductReviewAI generation failed', [
                'generation_id' => $generation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
