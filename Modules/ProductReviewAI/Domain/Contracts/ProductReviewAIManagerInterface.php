<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\ProductReviewAI\Domain\DTOs\AiGeneratedReviewDTO;
use Modules\ProductReviewAI\Domain\DTOs\AiPromptDTO;
use Modules\ProductReviewAI\Domain\DTOs\ExternalProductDTO;
use Modules\ProductReviewAI\Domain\DTOs\ExternalProductMappingDTO;
use Modules\ProductReviewAI\Domain\DTOs\GenerationDetailDTO;
use Modules\ProductReviewAI\Domain\DTOs\GenerationLogDTO;
use Modules\ProductReviewAI\Domain\DTOs\GenerationMonitorDTO;
use Modules\ProductReviewAI\Domain\DTOs\GenerationRequestResultDTO;
use Modules\ProductReviewAI\Domain\DTOs\GenerationStatsDTO;

/**
 * The module facade the HTTP layer depends on. Orchestration lives in the
 * Application actions; this interface exists so controllers stay thin and the
 * whole workflow is one mockable seam in tests.
 */
interface ProductReviewAIManagerInterface
{
    /**
     * Search an external source for products to map (admin confirms selection).
     *
     * @return list<ExternalProductDTO>
     */
    public function searchExternalProducts(string $productUuid, string $sourceCode, string $query): array;

    /**
     * Persist (or update) the internal-product ↔ external-product mapping.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function saveMapping(
        string $productUuid,
        string $sourceCode,
        string $externalId,
        ?string $externalUrl,
        array $metadata = [],
    ): ExternalProductMappingDTO;

    /**
     * @return list<ExternalProductMappingDTO>
     */
    public function listMappings(string $productUuid): array;

    /**
     * Queue a generation run. When the product already has published AI reviews
     * and `$confirmed` is false, returns a warning result and starts nothing.
     */
    public function requestGeneration(
        string $productUuid,
        int $adminUserId,
        int $count,
        string $sourceCode,
        bool $confirmed,
    ): GenerationRequestResultDTO;

    /**
     * A fresh run that always proceeds and never touches prior history.
     */
    public function regenerate(
        string $productUuid,
        int $adminUserId,
        int $count,
        string $sourceCode,
    ): GenerationRequestResultDTO;

    public function listGenerations(string $productUuid, int $perPage = 15): LengthAwarePaginator;

    /**
     * Read-only prompt browsing (prompts are authored in the DB/seeders).
     *
     * @param  array{name?: string, active?: bool}  $filters
     */
    public function listPrompts(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function getPrompt(int $promptId): AiPromptDTO;

    public function getGeneration(int $generationId): GenerationDetailDTO;

    // ── Generation monitoring (all products) ─────────────────────────────────

    /**
     * Global, paginated generation listing.
     *
     * @param  array{status?: string, product_uuid?: string, source_id?: int, created_by?: int, model?: string, date_from?: CarbonInterface, date_to?: CarbonInterface, sort?: string}  $filters
     * @return LengthAwarePaginator<GenerationMonitorDTO>
     */
    public function listAllGenerations(array $filters, int $perPage = 20): LengthAwarePaginator;

    /** Execution status + diagnostics of one run (404 when missing). */
    public function getGenerationMonitor(int $generationId): GenerationMonitorDTO;

    /**
     * Sanitized execution log, chronological (404 when the run is missing).
     * `$includeDiagnostics` adds redacted payloads — the caller must have
     * checked product-review-ai.view-logs.
     *
     * @return LengthAwarePaginator<GenerationLogDTO>
     */
    public function listGenerationLogs(int $generationId, bool $includeDiagnostics, int $perPage = 20): LengthAwarePaginator;

    /**
     * @param  array{product_uuid?: string, date_from?: CarbonInterface, date_to?: CarbonInterface}  $filters
     */
    public function generationStats(array $filters): GenerationStatsDTO;

    /**
     * Retry a failed run as a NEW linked run (409 when not allowed / not
     * reproducible; 503 when it could not be queued).
     */
    public function retryGeneration(int $generationId, int $adminUserId): GenerationMonitorDTO;

    /**
     * @param  array<string, mixed>  $changes  editable: name, rating, title, body
     */
    public function updateDraft(int $draftId, int $adminUserId, array $changes): AiGeneratedReviewDTO;

    public function approveDraft(int $draftId, int $adminUserId): AiGeneratedReviewDTO;

    public function rejectDraft(int $draftId, int $adminUserId): AiGeneratedReviewDTO;
}
