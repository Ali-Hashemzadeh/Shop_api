<?php

namespace Modules\Media\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Media\Domain\DTOs\MediaDTO;

/** @mixin MediaDTO */
class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var MediaDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'url' => $dto->url,
            'mime_type' => $dto->mimeType,
            'file_size' => $dto->fileSize,
            'original_name' => $dto->originalName,
        ];
    }
}
