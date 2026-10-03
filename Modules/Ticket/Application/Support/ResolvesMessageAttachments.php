<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Support;

use Modules\Media\Domain\Contracts\MediaManagerInterface;
use Modules\Media\Domain\DTOs\MediaDTO;

/**
 * Shared helper for the reply/note actions: turn a message's stored media ids
 * into resolved MediaDTOs (order preserved, missing ids dropped) through the
 * Media contract — the Ticket module never touches the media table itself.
 */
trait ResolvesMessageAttachments
{
    /**
     * @param  array<int|string>|null  $mediaIds
     * @return list<MediaDTO>
     */
    protected function resolveMessageAttachments(MediaManagerInterface $media, ?array $mediaIds): array
    {
        $mediaIds ??= [];
        $ids = array_values(array_unique(array_map('intval', $mediaIds)));

        if ($ids === []) {
            return [];
        }

        $map = [];

        foreach ($media->getMediaCollection($ids) as $dto) {
            $map[$dto->id] = $dto;
        }

        $attachments = [];

        foreach ($mediaIds as $id) {
            $id = (int) $id;

            if (isset($map[$id])) {
                $attachments[] = $map[$id];
            }
        }

        return $attachments;
    }
}
