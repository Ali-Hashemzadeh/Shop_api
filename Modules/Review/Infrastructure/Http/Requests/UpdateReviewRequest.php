<?php

declare(strict_types=1);

namespace Modules\Review\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Media\Domain\Contracts\MediaManagerInterface;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Review\Domain\Enums\ReviewSubjectType;
use Modules\Review\Domain\Models\Review;

/**
 * Owner-only edit of an existing review, addressed by its public code.
 *
 * Authorization runs before validation (403 before 422): the review is
 * resolved from the route's `uuid`, and a missing one is a 404 — also before
 * any validation error can surface. The edit re-resolves `verified_purchase`
 * against the review's own subject, so a commenter who has since bought the
 * product may now add a rating and photos.
 *
 * Semantics are full replacement (same shape as create), because every write
 * re-enters moderation as a new pending version.
 */
class UpdateReviewRequest extends FormRequest
{
    private ?Review $review = null;

    public function authorize(): bool
    {
        /** @var Review|null $review */
        $review = Review::query()->where('uuid', (string) $this->route('uuid'))->first();

        if ($review === null) {
            abort(404);
        }

        $this->review = $review;

        $user = $this->user();

        return (bool) $user?->can('review.create')
            && (int) $user->getAuthIdentifier() === (int) $review->user_id;
    }

    public function rules(): array
    {
        $rules = [
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

    private function isVerifiedPurchaser(): bool
    {
        $userId = $this->user()?->getAuthIdentifier();

        if (! $userId || $this->review === null || $this->review->subject_type !== ReviewSubjectType::Product) {
            return false;
        }

        return app(OrderManagerInterface::class)
            ->hasPurchasedProduct((int) $userId, (int) $this->review->subject_id);
    }
}
