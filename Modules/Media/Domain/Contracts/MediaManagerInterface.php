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
     * Delete a physical asset and its accompanying ledger record.
     */
    public function delete(int $id): bool;

    /** @return LengthAwarePaginator<MediaDTO> */
    public function listMedia(int $perPage = 15): LengthAwarePaginator;
}
