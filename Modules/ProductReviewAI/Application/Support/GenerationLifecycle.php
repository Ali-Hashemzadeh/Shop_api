<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\ProductReviewAI\Domain\Enums\GenerationFailureCode;
use Modules\ProductReviewAI\Domain\Enums\GenerationStage;
use Modules\ProductReviewAI\Domain\Enums\GenerationStatus;
use Modules\ProductReviewAI\Domain\Exceptions\GenerationOwnershipLostException;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;

/**
 * Every execution-state transition of a generation run, each one a single
 * conditional UPDATE (compare-and-set) — never read-modify-save:
 *
 *   claim     pending → processing          WHERE status = pending
 *   stage     processing (heartbeat)        WHERE status = processing AND execution_token = mine
 *   complete  processing → completed        WHERE status = processing AND execution_token = mine
 *   fail      → failed                      WHERE (processing AND mine) [OR pending, when unclaimed]
 *   reconcile processing → failed           WHERE status/stage/heartbeat/updated_at unchanged since inspected
 *
 * Consequences: only one delivery can ever claim a run; a terminal state
 * (completed/failed) is never overwritten by anything; a late or duplicate
 * worker discovers it lost ownership (GenerationOwnershipLostException) and
 * stops. No row lock is held across an external HTTP or AI call — the only
 * transaction is the short draft insert at completion.
 */
class GenerationLifecycle
{
    public function __construct(
        private readonly DiagnosticSanitizer $sanitizer,
    ) {}

    /**
     * Count a queue delivery that reached the worker (attempt_count) and record
     * the Laravel payload uuid once, for correlation.
     */
    public function recordDelivery(int $generationId, ?string $queueJobUuid): void
    {
        AiReviewGeneration::query()->whereKey($generationId)->increment('attempt_count');

        if ($queueJobUuid !== null && $queueJobUuid !== '') {
            AiReviewGeneration::query()
                ->whereKey($generationId)
                ->whereNull('queue_job_uuid')
                ->update(['queue_job_uuid' => mb_substr($queueJobUuid, 0, 64)]);
        }
    }

    /**
     * Atomically claim a pending run. Returns the start instant, or null when the
     * run is not pending (already claimed, finished, or failed).
     */
    public function claim(int $generationId, string $executionToken): ?CarbonInterface
    {
        $now = Carbon::now();

        $claimed = AiReviewGeneration::query()
            ->whereKey($generationId)
            ->where('status', GenerationStatus::Pending->value)
            ->update([
                'status' => GenerationStatus::Processing->value,
                'current_stage' => GenerationStage::Initializing->value,
                'started_at' => $now,
                'last_heartbeat_at' => $now,
                'execution_token' => $executionToken,
            ]);

        return $claimed === 1 ? $now : null;
    }

    /**
     * Record that the owner is about to execute `$stage` (and that it is alive).
     */
    public function enterStage(int $generationId, string $executionToken, GenerationStage $stage): void
    {
        $this->ownedUpdate($generationId, $executionToken, [
            'current_stage' => $stage->value,
            'last_heartbeat_at' => Carbon::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    public function saveAnalysis(int $generationId, string $executionToken, array $analysis): void
    {
        $this->ownedUpdate($generationId, $executionToken, [
            'analysis_json' => json_encode($analysis),
            'last_heartbeat_at' => Carbon::now(),
        ]);
    }

    /**
     * Mark the run completed and persist its drafts in ONE short transaction.
     * The conditional status update runs first: if ownership was lost (e.g. the
     * run was reconciled as abandoned), it throws and nothing is inserted.
     *
     * @param  callable(): void  $persistDrafts
     * @return int duration in milliseconds
     */
    public function complete(int $generationId, string $executionToken, CarbonInterface $startedAt, callable $persistDrafts): int
    {
        return DB::transaction(function () use ($generationId, $executionToken, $startedAt, $persistDrafts): int {
            $now = Carbon::now();
            $durationMs = $this->durationMs($startedAt, $now);

            $this->ownedUpdate($generationId, $executionToken, [
                'status' => GenerationStatus::Completed->value,
                'current_stage' => GenerationStage::Finished->value,
                'finished_at' => $now,
                'duration_ms' => $durationMs,
                'last_heartbeat_at' => $now,
            ]);

            $persistDrafts();

            return $durationMs;
        });
    }

    /**
     * Fail a run this caller owns (processing + matching token) or, when
     * `$includeUnclaimed`, a run nobody has claimed yet (pending). Completed or
     * already-failed runs — and runs owned by another delivery — are never touched.
     *
     * @return bool whether a row transitioned to failed
     */
    public function fail(
        int $generationId,
        ?string $executionToken,
        GenerationFailureCode $code,
        string $reason,
        ?GenerationStage $stage,
        ?CarbonInterface $startedAt,
        bool $includeUnclaimed = false,
    ): bool {
        if ($executionToken === null && ! $includeUnclaimed) {
            return false;
        }

        $now = Carbon::now();

        $updated = AiReviewGeneration::query()
            ->whereKey($generationId)
            ->where(function (Builder $q) use ($executionToken, $includeUnclaimed): void {
                if ($executionToken !== null) {
                    $q->where(function (Builder $owned) use ($executionToken): void {
                        $owned->where('status', GenerationStatus::Processing->value)
                            ->where('execution_token', $executionToken);
                    });
                }

                if ($includeUnclaimed) {
                    $q->orWhere('status', GenerationStatus::Pending->value);
                }
            })
            ->update($this->failureAttributes($code, $reason, $stage, $startedAt, $now));

        return $updated === 1;
    }

    /**
     * Fail an abandoned run, but only if nothing about it changed since it was
     * inspected (status, stage, heartbeat, updated_at). Any worker progress in
     * between makes this a no-op.
     */
    public function reconcileAbandoned(AiReviewGeneration $observed, string $reason): bool
    {
        $now = Carbon::now();
        $stage = $observed->current_stage !== null ? GenerationStage::tryFrom((string) $observed->current_stage) : null;

        $query = AiReviewGeneration::query()
            ->whereKey($observed->id)
            ->where('status', GenerationStatus::Processing->value);

        $this->whereUnchanged($query, 'current_stage', $observed->current_stage);
        $this->whereUnchanged($query, 'last_heartbeat_at', $observed->last_heartbeat_at);
        $this->whereUnchanged($query, 'updated_at', $observed->updated_at);

        return $query->update($this->failureAttributes(
            GenerationFailureCode::ProcessingTimeout,
            $reason,
            $stage,
            $observed->started_at,
            $now,
        )) === 1;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ownedUpdate(int $generationId, string $executionToken, array $attributes): void
    {
        $updated = AiReviewGeneration::query()
            ->whereKey($generationId)
            ->where('status', GenerationStatus::Processing->value)
            ->where('execution_token', $executionToken)
            ->update($attributes);

        if ($updated !== 1) {
            throw new GenerationOwnershipLostException(
                "Generation {$generationId} is no longer owned by this worker."
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function failureAttributes(
        GenerationFailureCode $code,
        string $reason,
        ?GenerationStage $stage,
        ?CarbonInterface $startedAt,
        CarbonInterface $now,
    ): array {
        return [
            'status' => GenerationStatus::Failed->value,
            'failure_code' => $code->value,
            'failure_reason' => $this->sanitizer->text($reason, 1000) ?? $code->description(),
            'failed_stage' => $stage?->value,
            'finished_at' => $now,
            'duration_ms' => $startedAt !== null ? $this->durationMs($startedAt, $now) : null,
        ];
    }

    private function durationMs(CarbonInterface $from, CarbonInterface $to): int
    {
        return max(0, (int) round(abs($from->diffInMilliseconds($to, false))));
    }

    /**
     * @param  Builder<AiReviewGeneration>  $query
     */
    private function whereUnchanged(Builder $query, string $column, mixed $value): void
    {
        $value === null ? $query->whereNull($column) : $query->where($column, $value);
    }
}
