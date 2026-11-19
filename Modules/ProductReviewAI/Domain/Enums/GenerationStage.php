<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Enums;

/**
 * The actual execution stage of a generation run (not a progress estimate).
 *
 *   queued → initializing → collecting_reviews → analyzing_reviews →
 *   generating_reviews → saving_drafts → finished
 *
 * The worker records a stage *before* executing it, and only after the previous
 * transition was persisted. `failed` is deliberately NOT a stage: it is a
 * GenerationStatus. A failed run keeps the stage it failed in (`failed_stage`).
 */
enum GenerationStage: string
{
    case Queued = 'queued';
    case Initializing = 'initializing';
    case CollectingReviews = 'collecting_reviews';
    case AnalyzingReviews = 'analyzing_reviews';
    case GeneratingReviews = 'generating_reviews';
    case SavingDrafts = 'saving_drafts';
    case Finished = 'finished';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
