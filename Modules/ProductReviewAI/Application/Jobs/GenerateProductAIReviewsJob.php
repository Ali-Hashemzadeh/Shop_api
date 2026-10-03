<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\ProductReviewAI\Application\Actions\ProcessGenerationAction;

/**
 * Runs the AI generation pipeline off the HTTP request. Scraping and AI calls
 * never happen inside a web request.
 *
 * The product context (title/description) is captured at request time and
 * carried in the payload, so the job never needs a cross-module Catalog lookup
 * by internal id.
 */
class GenerateProductAIReviewsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $productContext
     */
    public function __construct(
        public readonly int $generationId,
        public readonly array $productContext = [],
    ) {}

    public function handle(ProcessGenerationAction $action): void
    {
        $action->handle($this->generationId, $this->productContext);
    }
}
