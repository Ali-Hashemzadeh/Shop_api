<?php

declare(strict_types=1);

namespace Modules\Review\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Review\Domain\Enums\ReviewStatus;
use Modules\Review\Domain\Enums\ReviewSubjectType;

/**
 * Admin review listing across every status.
 *
 * `authorize()` runs before validation so an unauthorized admin surface call
 * is 403, never 422 bleed-through.
 */
class AdminIndexReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('review.view-admin');
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:'.implode(',', ReviewStatus::values())],
            'subject_type' => ['nullable', 'string', 'in:'.implode(',', ReviewSubjectType::values())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
