<?php

namespace Modules\Media\Domain\DTOs;

use Modules\Media\Domain\Models\Media;

class MediaDTO
{
    /**
     * Create a new immutable DTO instance.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly ?string $mimeType = null,
        public readonly ?int $fileSize = null,
        public readonly ?string $originalName = null
    ) {}

    /**
     * A helpful factory method to transform an Eloquent Media model
     * seamlessly into a clean, decoupled DTO.
     */
    public static function fromModel(Media $media): self
    {
        return new self(
            id: $media->id,
            url: $media->url, // Leverages the model accessor we built in Step 2
            mimeType: $media->mime_type,
            fileSize: $media->file_size,
            originalName: $media->original_name
        );
    }
}
