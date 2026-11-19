<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\DTOs;

use Carbon\CarbonInterface;

/**
 * One sanitized execution-log entry. `requestSummary`/`responseSummary` are
 * allow-listed scalar fields only; `omittedFields` names what was left out.
 * `diagnostics` is populated only for callers holding
 * product-review-ai.view-logs, and is still redacted.
 */
final class GenerationLogDTO
{
    /**
     * @param  array<string, mixed>|null  $requestSummary
     * @param  array<string, mixed>|null  $responseSummary
     * @param  list<string>  $omittedFields
     * @param  array{request: mixed, response: mixed}|null  $diagnostics
     */
    public function __construct(
        public readonly int $id,
        public readonly int $generationId,
        public readonly string $type,
        public readonly string $status,
        public readonly ?string $stage,
        public readonly ?string $error,
        public readonly ?array $requestSummary,
        public readonly ?array $responseSummary,
        public readonly array $omittedFields,
        public readonly ?CarbonInterface $createdAt,
        public readonly ?array $diagnostics = null,
    ) {}
}
