<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Modules\Media\Domain\Contracts\MediaManagerInterface;

/**
 * Comment attachments are pre-uploaded Media ids that the caller must own —
 * checked through MediaManagerInterface::ownedByUser() (the same boundary the
 * Ticket module uses), never a media-table query. A missing or foreign-owned id
 * is a 422 on `media_ids`.
 */
trait ValidatesBlogMediaOwnership
{
    /** @return array<string, mixed> */
    protected function mediaIdsRules(): array
    {
        return [
            'media_ids' => ['sometimes', 'nullable', 'array', 'max:5'],
            'media_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    protected function validateMediaOwnership(Validator $validator): void
    {
        $ids = $this->input('media_ids');

        if (! is_array($ids) || $ids === [] || $validator->errors()->has('media_ids.*')) {
            return;
        }

        if (! app(MediaManagerInterface::class)->ownedByUser($ids, (int) $this->user()->getAuthIdentifier())) {
            $validator->errors()->add('media_ids', 'One or more attachments were not found or do not belong to you.');
        }
    }
}
