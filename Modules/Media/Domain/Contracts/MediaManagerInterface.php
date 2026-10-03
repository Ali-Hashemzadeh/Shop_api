<?php

namespace Modules\Media\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Modules\Media\Domain\DTOs\MediaDTO;

interface MediaManagerInterface
{
    /**
     * Handle a raw HTTP file upload, persist it locally,
     * log it in the ledger, and return a clean DTO.
     *
     * @param  string  $folder  Destination folder inside storage/app/public/ (e.g., 'products')
     */
    public function upload(UploadedFile $file, string $folder): MediaDTO;

    /**
     * Retrieve a specific media record by its integer ID.
     */
    public function getMedia(int $id): ?MediaDTO;

    /**
     * Retrieve a collection of media records matching an array of IDs.
     * Useful for hydration maps (e.g., fetching a product gallery).
     *
     * * @param array<int> $ids
     * @return Collection<MediaDTO>
     */
    public function getMediaCollection(array $ids): Collection;

    /**
     * Whether every id in the set exists AND was uploaded by the given user.
     *
     * Returns false as soon as any id is missing or owned by someone else, so a
     * consuming module can reject an attempt to attach media the caller does not
     * own — without ever querying the media table itself. An empty set is
     * trivially true (nothing to attach).
     *
     * @param  array<int>  $ids
     */
    public function ownedByUser(array $ids, int $userId): bool;

    /**
     * Delete a physical asset and its accompanying ledger record.
     */
    public function delete(int $id): bool;

    /** @return LengthAwarePaginator<MediaDTO> */
    public function listMedia(int $perPage = 15): LengthAwarePaginator;
}
