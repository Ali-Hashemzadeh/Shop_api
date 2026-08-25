<?php

declare(strict_types=1);

namespace Modules\Review\Domain\Enums;

/**
 * The whitelist of subject kinds a review may attach to.
 *
 * `subject_type` is never free text: it must be one of these values, and the
 * Form Request builds its `in:` rule from this list. Adding blog support later
 * means adding a case here — no schema change, no new string literals
 * scattered through the module.
 */
enum ReviewSubjectType: string
{
    case Product = 'product';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
