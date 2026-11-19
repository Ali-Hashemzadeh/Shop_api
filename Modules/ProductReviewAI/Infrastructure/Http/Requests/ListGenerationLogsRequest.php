<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Requests;

/**
 * GET /admin/ai-reviews/generations/{generation}/logs.
 *
 * The default view (sanitized summaries) needs product-review-ai.manage.
 * `diagnostics=1` (redacted full payloads) additionally needs the elevated
 * product-review-ai.view-logs — otherwise 403 before validation.
 */
class ListGenerationLogsRequest extends ProductReviewAIRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        return ! $this->wantsDiagnostics() || (bool) $this->user()?->can('product-review-ai.view-logs');
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'diagnostics' => ['sometimes', 'boolean'],
        ];
    }

    public function wantsDiagnostics(): bool
    {
        return $this->boolean('diagnostics');
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 20);
    }
}
