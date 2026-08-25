<?php

declare(strict_types=1);

namespace Modules\Review\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Media\Domain\Contracts\MediaManagerInterface;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Review\Domain\Enums\ReviewSubjectType;

/**
 * Create (or upgrade-in-place) a review.
 *
 * The verified-purchase gating is validation, not an Action correction: the
 * purchaser's privileges and the non-purchaser's prohibitions are both decided
 * here, so a non-purchaser who sends `rating` or `gallery_media_ids` gets a
 * clean 422 instead of silently dropped fields.
 *
 * `authorize()` runs before validation: unauthorized callers get 403, never
 * 422 bleed-through.
 */
class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('review.create');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('subject_id')) {
            $this->merge(['subject_id' => (int) $this->input('subject_id')]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'subject_type' => ['required', 'string', 'in:'.implode(',', ReviewSubjectType::values())],
            'subject_id' => ['required', 'integer', 'min:1'],
            'body' => ['required', 'string'],
            'gallery_media_ids' => ['present', 'array'],
        ];

        if ($this->isVerifiedPurchaser()) {
            $rules['rating'] = ['required', 'integer', 'min:1', 'max:5'];
            $rules['gallery_media_ids.*'] = [
                'integer',
                'min:1',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (app(MediaManagerInterface::class)->getMedia((int) $value) === null) {
                        $fail("The media at {$attribute} does not exist.");
                    }
                },
            ];
        } else {
            // Absent or null is fine; any actual value is rejected outright.
            $rules['rating'] = [
                'sometimes',
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && $value !== '') {
                        $fail('Only verified purchasers can submit a star rating.');
                    }
                },
            ];
            $rules['gallery_media_ids'] = [
                'sometimes',
                'array',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_array($value) && $value !== []) {
                        $fail('Only verified purchasers can attach photos.');
                    }
                },
            ];
        }

        return $rules;
    }

    /**
     * Purchase status is resolved server-side from the Order contract at
     * validation time — the same question the Action will re-ask when it writes.
     */
    private function isVerifiedPurchaser(): bool
    {
        $userId = $this->user()?->getAuthIdentifier();
        $subjectId = (int) $this->input('subject_id');

        if (! $userId || ReviewSubjectType::tryFrom((string) $this->input('subject_type')) !== ReviewSubjectType::Product) {
            return false;
        }

        return app(OrderManagerInterface::class)->hasPurchasedProduct((int) $userId, $subjectId);
    }
}
