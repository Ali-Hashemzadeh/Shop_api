<?php

namespace Modules\Media\Domain\Contracts;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Modules\Media\Domain\DTOs\MediaDTO;

interface MediaManagerInterface
{
    /**
     * Handle a raw HTTP file upload, persist it locally,
     * log it in the ledger, and return a clean DTO.
     * * @param UploadedFile $file
     * @param string $folder Destination folder inside storage/app/public/ (e.g., 'products')
     * @return MediaDTO
     */
    public function upload(UploadedFile $file, string $folder): MediaDTO;

    /**
     * Retrieve a specific media record by its integer ID.
     * * @param int $id
     * @return MediaDTO|null
     */
    public function getMedia(int $id): ?MediaDTO;

    /**
     * Retrieve a collection of media records matching an array of IDs.
     * Useful for hydration maps (e.g., fetching a product gallery).
     * * @param array<int> $ids
     * @return Collection<MediaDTO>
     */
    public function getMediaCollection(array $ids): Collection;

    /**
     * Delete a physical asset and its accompanying ledger record.
     * * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;
}
