<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\BrandDTO;
use Modules\Media\Domain\Contracts\MediaManagerInterface;

class CreateBrandAction
{
    public function __construct(
        private readonly CatalogManagerInterface $catalog,
        private readonly MediaManagerInterface $media,
    ) {}

    /**
     * Create a brand, auto-generating the slug from the name when omitted.
     * An image file upload OR a pre-existing media_id may be supplied — not both.
     */
    public function handle(array $data, ?UploadedFile $image = null): BrandDTO
    {
        $mediaId = $this->resolveMediaId($image, $data['media_id'] ?? null);

        return $this->catalog->createBrand([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name'], '-', null),
            'media_id' => $mediaId,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    private function resolveMediaId(?UploadedFile $upload, mixed $existingId): ?int
    {
        if ($upload !== null) {
            return $this->media->upload($upload, 'brands')->id;
        }

        return $existingId !== null ? (int) $existingId : null;
    }
}
