<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Requests;

/**
 * POST /admin/ai-reviews/generations/{generation}/retry. Retrying repeats paid
 * external/AI calls, so it needs the elevated product-review-ai.retry on top of
 * product-review-ai.manage.
 */
class RetryGenerationRequest extends ProductReviewAIRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && (bool) $this->user()?->can('product-review-ai.retry');
    }
}
