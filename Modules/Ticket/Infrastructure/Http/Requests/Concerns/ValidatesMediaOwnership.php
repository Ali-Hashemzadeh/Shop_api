<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Modules\Media\Domain\Contracts\MediaManagerInterface;

/**
 * Shared `media_ids` validation for the ticket write requests.
 *
 * Attachments are pre-uploaded Media ids. The caller may only attach media they
 * themselves uploaded — enforced through MediaManagerInterface (never a direct
 * media-table query), which closes the "attach another user's media" attack for
 * customers, support, and admins uniformly. A missing id or a foreign-owned id
 * both fail with 422 on `media_ids`.
 */
trait ValidatesMediaOwnership
{
    /**
     * Merge into a request's own rules().
     *
     * @return array<string, mixed>
     */
    protected function mediaIdsRules(): array
    {
        return [
            'media_ids' => ['sometimes', 'nullable', 'array'],
            'media_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * Call from the request's withValidator() closure. Skips when no attachments
     * were sent; otherwise every id must exist and belong to the caller.
     */
    protected function validateMediaOwnership(Validator $validator): void
    {
        $ids = $this->input('media_ids');

        if (! is_array($ids) || $ids === []) {
            return;
        }

        $userId = (int) $this->user()->getAuthIdentifier();

        if (! app(MediaManagerInterface::class)->ownedByUser($ids, $userId)) {
            $validator->errors()->add(
                'media_ids',
                'One or more attachments were not found or do not belong to you.',
            );
        }
    }
}
