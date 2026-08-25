<?php

declare(strict_types=1);

namespace Modules\Review\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Review\Domain\Enums\ReviewStatus;

/**
 * Moderation decision. `pending` is not a legal target here (system-only, set
 * on create/edit), so validation rejects it before any Action runs.
 */
class ModerateReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('review.moderate');
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.implode(',', ReviewStatus::moderationTargets())],
        ];
    }
}
