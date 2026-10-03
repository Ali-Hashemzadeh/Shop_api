<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Enums;

/**
 * Lifecycle of a single AI generation run.
 *
 * A run is created `pending`, flipped to `processing` when the queue job starts,
 * and ends `completed` or `failed`. Runs are never deleted — the history is the
 * audit trail — so a re-run creates a new row rather than mutating an old one.
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
