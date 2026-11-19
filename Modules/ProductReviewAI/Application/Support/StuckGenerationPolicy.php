<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\ProductReviewAI\Domain\Enums\GenerationStatus;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;

/**
 * The ONE definition of "stuck", shared by the monitoring API (per-row flag,
 * dashboard count) and the reconciliation command — so they can never disagree.
 *
 * SUSPECTED (reported only, never changes state):
 *   - pending   and created more than `pending_threshold_seconds` ago, or
 *   - processing and its last recorded activity is older than the effective
 *     inactivity threshold, or it started more than `processing_timeout_seconds` ago.
 *
 * ABANDONED (may be failed with PROCESSING_TIMEOUT by the command):
 *   - processing, started more than `processing_timeout_seconds` ago AND
 *     inactive beyond the effective inactivity threshold, AND automatic failing
 *     is enabled AND the processing timeout exceeds the job's own $timeout (so a
 *     correctly configured worker would already have been stopped).
 *
 * A pending run is never ABANDONED: "no worker picked it up yet" is not proof the
 * job is lost (stopped worker, backlog).
 *
 * Heartbeats are only written at stage boundaries, so a single blocking HTTP call
 * can legitimately look inactive. The effective inactivity threshold is therefore
 * never below the longest single blocking stage implied by the configured HTTP
 * timeouts (+60 s margin).
 *
 * Historical runs (no started_at / heartbeat) are evaluated against their real
 * recorded `updated_at` — the time their status last changed — never a made-up
 * time.
 */
final class StuckGenerationPolicy
{
    private const MARGIN_SECONDS = 60;

    public function pendingThreshold(): int
    {
        return max(60, (int) config('product_review_ai.monitoring.pending_threshold_seconds', 900));
    }

    public function inactivityThreshold(): int
    {
        return max(
            (int) config('product_review_ai.monitoring.inactivity_threshold_seconds', 1200),
            $this->longestBlockingStageSeconds() + self::MARGIN_SECONDS,
        );
    }

    public function processingTimeout(): int
    {
        return max(60, (int) config('product_review_ai.monitoring.processing_timeout_seconds', 1800));
    }

    public function jobTimeout(): int
    {
        return max(1, (int) config('product_review_ai.queue.job_timeout', 1200));
    }

    /**
     * Automatic failing is allowed only when it is enabled and the processing
     * timeout is strictly longer than the job timeout.
     */
    public function canAutoFail(): bool
    {
        return (bool) config('product_review_ai.monitoring.auto_fail_abandoned', true)
            && $this->processingTimeout() > $this->jobTimeout();
    }

    /**
     * The longest time one stage can block without writing a heartbeat:
     * collection pages through the source (pages × per-request timeout); an AI
     * stage is a single request.
     */
    public function longestBlockingStageSeconds(): int
    {
        $collection = max(1, (int) config('product_review_ai.sources.digikala.max_pages', 30))
            * max(1, (int) config('product_review_ai.sources.digikala.timeout', 30));
        $ai = max(1, (int) config('product_review_ai.ai.providers.avalai.timeout', 120));

        return max($collection, $ai);
    }

    /**
     * Restrict a query to suspected-stuck runs (same rules as isSuspected()).
     *
     * @param  Builder<AiReviewGeneration>  $query
     * @return Builder<AiReviewGeneration>
     */
    public function applySuspectedScope(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= Carbon::now();
        $pendingCutoff = $now->copy()->subSeconds($this->pendingThreshold());
        $inactiveCutoff = $now->copy()->subSeconds($this->inactivityThreshold());
        $timeoutCutoff = $now->copy()->subSeconds($this->processingTimeout());

        return $query->where(function (Builder $q) use ($pendingCutoff, $inactiveCutoff, $timeoutCutoff): void {
            $q->where(function (Builder $p) use ($pendingCutoff): void {
                $p->where('status', GenerationStatus::Pending->value)
                    ->where('created_at', '<=', $pendingCutoff);
            })->orWhere(function (Builder $p) use ($inactiveCutoff, $timeoutCutoff): void {
                $p->where('status', GenerationStatus::Processing->value)
                    ->where(function (Builder $a) use ($inactiveCutoff, $timeoutCutoff): void {
                        $a->whereRaw('COALESCE(last_heartbeat_at, started_at, updated_at) <= ?', [$inactiveCutoff])
                            ->orWhereRaw('COALESCE(started_at, updated_at) <= ?', [$timeoutCutoff]);
                    });
            });
        });
    }

    public function isSuspected(AiReviewGeneration $generation, ?CarbonInterface $now = null): bool
    {
        $now ??= Carbon::now();
        $status = $generation->status;

        if ($status === GenerationStatus::Pending) {
            return $generation->created_at !== null
                && $generation->created_at->lte($now->copy()->subSeconds($this->pendingThreshold()));
        }

        if ($status !== GenerationStatus::Processing) {
            return false;
        }

        return $this->inactiveSeconds($generation, $now) >= $this->inactivityThreshold()
            || $this->runningSeconds($generation, $now) >= $this->processingTimeout();
    }

    public function isAbandoned(AiReviewGeneration $generation, ?CarbonInterface $now = null): bool
    {
        $now ??= Carbon::now();

        return $generation->status === GenerationStatus::Processing
            && $this->runningSeconds($generation, $now) >= $this->processingTimeout()
            && $this->inactiveSeconds($generation, $now) >= $this->inactivityThreshold();
    }

    public function inactiveSeconds(AiReviewGeneration $generation, CarbonInterface $now): int
    {
        $last = $generation->last_heartbeat_at ?? $generation->started_at ?? $generation->updated_at;

        return $last === null ? 0 : max(0, (int) $last->diffInSeconds($now, false));
    }

    private function runningSeconds(AiReviewGeneration $generation, CarbonInterface $now): int
    {
        $start = $generation->started_at ?? $generation->updated_at;

        return $start === null ? 0 : max(0, (int) $start->diffInSeconds($now, false));
    }
}
