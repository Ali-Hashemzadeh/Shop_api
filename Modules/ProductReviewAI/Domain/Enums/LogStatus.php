<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Enums;

/**
 * Outcome recorded on a generation log row.
 */
enum LogStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
}
