<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for the whole feature. Authorization is the single capability
 * `product-review-ai.manage` (admin only — never support, never customer).
 * `authorize()` runs before validation, so unauthorized callers get 403.
 */
abstract class ProductReviewAIRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('product-review-ai.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
