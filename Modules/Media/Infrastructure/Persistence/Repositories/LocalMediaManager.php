<?php

namespace Modules\Media\Infrastructure\Persistence\Repositories;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Domain\Contracts\MediaManagerInterface;
use Modules\Media\Domain\DTOs\MediaDTO;
use Modules\Media\Domain\Models\Media;

class LocalMediaManager implements MediaManagerInterface
{
    /**
     * Handle a raw HTTP file upload, persist it locally,
     * log it in the ledger, and return a clean DTO.
     */
    public function upload(UploadedFile $file, string $folder): MediaDTO
    {
        // 1. Store the physical file on the local public disk inside storage/app/public/{$folder}
        // This makes it publicly accessible via the storage symlink
        $path = $file->store($folder, 'public');

        // 2. Create the record in our local media ledger database table
        $media = Media::create([
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'original_name' => $file->getClientOriginalName(),
        ]);

        // 3. Return the decoupled DTO back to the calling module
        return MediaDTO::fromModel($media);
    }

    /**
     * Retrieve a specific media record by its integer ID.
     */
    public function getMedia(int $id): ?MediaDTO
    {
        $media = Media::find($id);

        if (! $media) {
            return null;
        }

        return MediaDTO::fromModel($media);
    }

    /**
     * Retrieve a collection of media records matching an array of IDs.
     */
    public function getMediaCollection(array $ids): Collection
    {
        return Media::whereIn('id', $ids)
            ->get()
            ->map(fn (Media $media) => MediaDTO::fromModel($media));
    }

    /**
     * Delete a physical asset and its accompanying ledger record.
     */
    public function delete(int $id): bool
    {
        $media = Media::find($id);

        if (! $media) {
            return false;
        }

        // Remove the physical file from the local drive
        if (Storage::disk('public')->exists($media->file_path)) {
            Storage::disk('public')->delete($media->file_path);
        }

        // Wipe the database tracking record
        return (bool) $media->delete();
    }
}
