<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\ProductReviewAI\Application\Support\GenerationLifecycle;
use Modules\ProductReviewAI\Application\Support\GenerationLogWriter;
use Modules\ProductReviewAI\Application\Support\StuckGenerationPolicy;
use Modules\ProductReviewAI\Domain\Enums\GenerationFailureCode;
use Modules\ProductReviewAI\Domain\Enums\GenerationLogType;
use Modules\ProductReviewAI\Domain\Enums\GenerationStatus;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Throwable;

/**
 * Backs `ai-reviews:detect-stuck-generations` (scheduled every five minutes).
 *
 * Inspects every pending/processing run against StuckGenerationPolicy:
 *  - SUSPECTED runs are reported (app log + counters) and left untouched;
 *  - ABANDONED runs are failed with PROCESSING_TIMEOUT — only when automatic
 *    failing is allowed by the policy, and only through a compare-and-set
 *    update that requires the row to be unchanged since it was inspected. If the
 *    worker made progress in between, nothing happens; if a late worker tries to
 *    finish afterwards, its conditional writes match no row and it stops.
 *
 * Idempotent and safe on several instances at once: a second run (or a
 * concurrent instance) finds the row already failed and skips it.
 */
class DetectStuckGenerationsAction
{
    public function __construct(
        private readonly StuckGenerationPolicy $policy,
        private readonly GenerationLifecycle $lifecycle,
        private readonly GenerationLogWriter $log,
    ) {}

    /**
     * @return array{inspected: int, suspected: int, reconciled: int, auto_fail_enabled: bool}
     */
    public function handle(bool $dryRun = false): array
    {
        $now = Carbon::now();
        $autoFail = ! $dryRun && $this->policy->canAutoFail();
        $result = ['inspected' => 0, 'suspected' => 0, 'reconciled' => 0, 'auto_fail_enabled' => $autoFail];
        $suspectedIds = [];

        AiReviewGeneration::query()
            ->select([
                'id', 'status', 'current_stage', 'created_at', 'updated_at',
                'started_at', 'last_heartbeat_at',
            ])
            ->whereIn('status', [GenerationStatus::Pending->value, GenerationStatus::Processing->value])
            ->chunkById(200, function ($generations) use ($now, $autoFail, &$result, &$suspectedIds): void {
                foreach ($generations as $generation) {
                    /** @var AiReviewGeneration $generation */
                    $result['inspected']++;

                    if (! $this->policy->isSuspected($generation, $now)) {
                        continue;
                    }

                    $result['suspected']++;
                    $suspectedIds[] = (int) $generation->id;

                    if ($autoFail && $this->policy->isAbandoned($generation, $now) && $this->reconcile($generation, $now)) {
                        $result['reconciled']++;
                    }
                }
            });

        if ($result['suspected'] > 0) {
            Log::warning('ProductReviewAI suspected stuck generations', [
                'suspected' => $result['suspected'],
                'reconciled' => $result['reconciled'],
                'generation_ids' => array_slice($suspectedIds, 0, 50),
            ]);
        }

        return $result;
    }

    private function reconcile(AiReviewGeneration $generation, Carbon $now): bool
    {
        $inactive = $this->policy->inactiveSeconds($generation, $now);
        $reason = sprintf(
            'Processing was abandoned: no recorded activity for %d minutes (stage: %s).',
            intdiv($inactive, 60),
            $generation->current_stage ?? 'unknown',
        );

        try {
            if (! $this->lifecycle->reconcileAbandoned($generation, $reason)) {
                return false; // changed since inspection — a worker is alive, or another instance won
            }
        } catch (Throwable $e) {
            Log::error('ProductReviewAI could not reconcile a stuck generation', [
                'generation_id' => $generation->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        Log::warning('ProductReviewAI reconciled an abandoned generation', [
            'generation_id' => $generation->id,
            'stage' => $generation->current_stage,
            'inactive_seconds' => $inactive,
        ]);

        try {
            $this->log->failure($generation->id, GenerationLogType::StuckReconciled, $reason, [
                'stage' => $generation->current_stage,
                'failure_code' => GenerationFailureCode::ProcessingTimeout->value,
                'inactive_seconds' => $inactive,
                'reconciled_by' => 'ai-reviews:detect-stuck-generations',
            ]);
        } catch (Throwable $e) {
            Log::error('ProductReviewAI could not log a reconciliation', ['generation_id' => $generation->id, 'error' => $e->getMessage()]);
        }

        return true;
    }
}
