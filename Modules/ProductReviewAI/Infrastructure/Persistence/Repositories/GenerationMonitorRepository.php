<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Persistence\Repositories;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\ProductReferenceDTO;
use Modules\ProductReviewAI\Application\Support\DiagnosticSanitizer;
use Modules\ProductReviewAI\Application\Support\StuckGenerationPolicy;
use Modules\ProductReviewAI\Domain\DTOs\GenerationLogDTO;
use Modules\ProductReviewAI\Domain\DTOs\GenerationMonitorDTO;
use Modules\ProductReviewAI\Domain\DTOs\GenerationStatsDTO;
use Modules\ProductReviewAI\Domain\Enums\DraftStatus;
use Modules\ProductReviewAI\Domain\Enums\GenerationFailureCode;
use Modules\ProductReviewAI\Domain\Enums\GenerationLogType;
use Modules\ProductReviewAI\Domain\Enums\GenerationStage;
use Modules\ProductReviewAI\Domain\Enums\GenerationStatus;
use Modules\ProductReviewAI\Domain\Models\AiGeneratedReview;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Modules\ProductReviewAI\Domain\Models\AiReviewGenerationLog;

/**
 * Read side of generation monitoring (global list, detail, logs, stats).
 *
 * Query discipline for a frequently refreshed dashboard:
 *  - database-level pagination with a deterministic tiebreaker (id);
 *  - explicit column lists — the heavy JSON (`analysis_json`, `request_context`)
 *    is never loaded for list rows; snapshot presence is a computed flag;
 *  - drafts counted with withCount, sources and the retry child eager-loaded,
 *    product labels fetched in ONE Catalog contract call per page — no N+1 and
 *    no query against Catalog's tables;
 *  - stats are three aggregate queries (status counts, stuck count, duration).
 */
final class GenerationMonitorRepository
{
    private const LIST_COLUMNS = [
        'id', 'product_id', 'source_id', 'model', 'prompt_name', 'prompt_version',
        'count_requested', 'status', 'current_stage', 'failure_code', 'failure_reason',
        'failed_stage', 'created_by', 'created_at', 'updated_at', 'started_at',
        'finished_at', 'duration_ms', 'last_heartbeat_at', 'attempt_count', 'retry_of_id',
    ];

    public function __construct(
        private readonly CatalogManagerInterface $catalog,
        private readonly StuckGenerationPolicy $stuckPolicy,
        private readonly DiagnosticSanitizer $sanitizer,
    ) {}

    /**
     * @param  array{status?: string, product_id?: int, source_id?: int, created_by?: int, model?: string, date_from?: CarbonInterface, date_to?: CarbonInterface, sort?: string}  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->monitorQuery();
        $this->applyFilters($query, $filters);

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            // Only finished runs have a recorded duration; the rest sort last.
            'longest_duration' => $query->orderByRaw('duration_ms IS NULL')->orderByDesc('duration_ms')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        $page = $query->paginate($perPage);
        $products = $this->catalog->getProductReferencesByIds(
            $page->getCollection()->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values()->all()
        );
        $now = Carbon::now();

        return $page->through(fn (AiReviewGeneration $g): GenerationMonitorDTO => $this->toDTO($g, $products[(int) $g->product_id] ?? null, $now));
    }

    public function find(int $generationId): ?GenerationMonitorDTO
    {
        /** @var AiReviewGeneration|null $generation */
        $generation = $this->monitorQuery()->whereKey($generationId)->first();

        if ($generation === null) {
            return null;
        }

        $draftCounts = array_fill_keys(DraftStatus::values(), 0);

        AiGeneratedReview::query()
            ->toBase()
            ->where('generation_id', $generationId)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->each(function ($count, $status) use (&$draftCounts): void {
                $draftCounts[(string) $status] = (int) $count;
            });

        $products = $this->catalog->getProductReferencesByIds([(int) $generation->product_id]);

        return $this->toDTO($generation, $products[(int) $generation->product_id] ?? null, Carbon::now(), $draftCounts);
    }

    public function exists(int $generationId): bool
    {
        return AiReviewGeneration::query()->whereKey($generationId)->exists();
    }

    public function logs(int $generationId, bool $includeDiagnostics, int $perPage): LengthAwarePaginator
    {
        return AiReviewGenerationLog::query()
            ->where('generation_id', $generationId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($perPage)
            ->through(fn (AiReviewGenerationLog $log): GenerationLogDTO => $this->toLogDTO($log, $includeDiagnostics));
    }

    /**
     * @param  array{product_id?: int, date_from?: CarbonInterface, date_to?: CarbonInterface}  $filters
     */
    public function stats(array $filters): GenerationStatsDTO
    {
        $base = AiReviewGeneration::query();
        $this->applyFilters($base, $filters);

        $counts = (clone $base)->toBase()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($c) => (int) $c);

        $count = static fn (GenerationStatus $s): int => (int) ($counts[$s->value] ?? 0);
        $completed = $count(GenerationStatus::Completed);
        $failed = $count(GenerationStatus::Failed);

        $suspected = $this->stuckPolicy->applySuspectedScope(clone $base)->count();

        $duration = (clone $base)->toBase()
            ->where('status', GenerationStatus::Completed->value)
            ->whereNotNull('duration_ms')
            ->selectRaw('AVG(duration_ms) AS avg_ms, COUNT(*) AS samples')
            ->first();

        $samples = (int) ($duration->samples ?? 0);

        return new GenerationStatsDTO(
            total: (int) $counts->sum(),
            pending: $count(GenerationStatus::Pending),
            processing: $count(GenerationStatus::Processing),
            completed: $completed,
            failed: $failed,
            suspectedStuck: $suspected,
            successRate: ($completed + $failed) > 0 ? round($completed / ($completed + $failed), 4) : null,
            averageDurationMs: $samples > 0 ? (int) round((float) $duration->avg_ms) : null,
            durationSampleSize: $samples,
            thresholds: [
                'pending_threshold_seconds' => $this->stuckPolicy->pendingThreshold(),
                'inactivity_threshold_seconds' => $this->stuckPolicy->inactivityThreshold(),
                'processing_timeout_seconds' => $this->stuckPolicy->processingTimeout(),
            ],
        );
    }

    /**
     * @return Builder<AiReviewGeneration>
     */
    private function monitorQuery(): Builder
    {
        return AiReviewGeneration::query()
            ->select(self::LIST_COLUMNS)
            ->selectRaw('CASE WHEN request_context IS NULL THEN 0 ELSE 1 END AS has_request_context')
            ->with(['source:id,code,name', 'retries:id,retry_of_id,status'])
            ->withCount('reviews');
    }

    /**
     * @param  Builder<AiReviewGeneration>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(isset($filters['product_id']), fn (Builder $q) => $q->where('product_id', $filters['product_id']))
            ->when(isset($filters['source_id']), fn (Builder $q) => $q->where('source_id', $filters['source_id']))
            ->when(isset($filters['created_by']), fn (Builder $q) => $q->where('created_by', $filters['created_by']))
            ->when(isset($filters['model']), fn (Builder $q) => $q->where('model', $filters['model']))
            ->when(isset($filters['date_from']), fn (Builder $q) => $q->where('created_at', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn (Builder $q) => $q->where('created_at', '<=', $filters['date_to']));
    }

    /**
     * @param  array<string, int>|null  $draftCounts
     */
    private function toDTO(AiReviewGeneration $g, ?ProductReferenceDTO $product, CarbonInterface $now, ?array $draftCounts = null): GenerationMonitorDTO
    {
        $status = $g->status instanceof GenerationStatus ? $g->status : GenerationStatus::from((string) $g->status);
        $failureCode = $g->failure_code !== null ? GenerationFailureCode::tryFrom((string) $g->failure_code) : null;
        $retry = $g->retries->first();
        $hasContext = (int) ($g->getAttribute('has_request_context') ?? 0) === 1;

        return new GenerationMonitorDTO(
            id: (int) $g->id,
            productId: (int) $g->product_id,
            productUuid: $product?->uuid,
            productTitle: $product?->title,
            sourceId: $g->source_id !== null ? (int) $g->source_id : null,
            sourceCode: $g->source?->code,
            sourceName: $g->source?->name,
            model: (string) $g->model,
            promptName: $g->prompt_name,
            promptVersion: $g->prompt_version !== null ? (int) $g->prompt_version : null,
            countRequested: (int) $g->count_requested,
            countGenerated: (int) ($g->reviews_count ?? 0),
            status: $status->value,
            currentStage: $g->current_stage,
            failureCode: $g->failure_code,
            failureReason: $this->sanitizer->text($g->failure_reason, 500),
            failedStage: $g->failed_stage,
            failureRetryable: $failureCode?->isRetryable(),
            createdBy: $g->created_by !== null ? (int) $g->created_by : null,
            createdAt: $g->created_at,
            startedAt: $g->started_at,
            finishedAt: $g->finished_at,
            durationMs: $g->duration_ms !== null ? (int) $g->duration_ms : null,
            lastHeartbeatAt: $g->last_heartbeat_at,
            attemptCount: (int) ($g->attempt_count ?? 0),
            retryOfId: $g->retry_of_id !== null ? (int) $g->retry_of_id : null,
            retryGenerationId: $retry !== null ? (int) $retry->id : null,
            isSuspectedStuck: $this->stuckPolicy->isSuspected($g, $now),
            canRetry: $status === GenerationStatus::Failed && $retry === null && $hasContext,
            draftCounts: $draftCounts,
        );
    }

    private function toLogDTO(AiReviewGenerationLog $log, bool $includeDiagnostics): GenerationLogDTO
    {
        $type = $log->type instanceof GenerationLogType ? $log->type : GenerationLogType::tryFrom((string) $log->type);
        $request = is_array($log->request) ? $log->request : null;
        $response = is_array($log->response) ? $log->response : null;

        $stage = $type?->impliedStage()?->value;
        if ($stage === null && isset($request['stage']) && is_string($request['stage'])) {
            $stage = GenerationStage::tryFrom($request['stage'])?->value;
        }

        [$requestSummary, $omittedRequest] = $this->sanitizer->summary($request);
        [$responseSummary, $omittedResponse] = $this->sanitizer->summary($response);

        $omitted = array_merge(
            array_map(static fn (string $k): string => 'request.'.$k, $omittedRequest),
            array_map(static fn (string $k): string => 'response.'.$k, $omittedResponse),
        );

        return new GenerationLogDTO(
            id: (int) $log->id,
            generationId: (int) $log->generation_id,
            type: $type?->value ?? (string) $log->getRawOriginal('type'),
            status: (string) $log->status,
            stage: $stage,
            error: $this->sanitizer->text($log->error, 500),
            requestSummary: $requestSummary,
            responseSummary: $responseSummary,
            omittedFields: $omitted,
            createdAt: $log->created_at,
            diagnostics: $includeDiagnostics ? [
                'request' => $this->sanitizer->redactPayload($request),
                'response' => $this->sanitizer->redactPayload($response),
            ] : null,
        );
    }
}
