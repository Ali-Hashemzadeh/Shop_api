<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\ProductDTO;
use Modules\Media\Domain\Contracts\MediaManagerInterface;

class CreateProductAction
{
    public function __construct(
        private readonly CatalogManagerInterface $catalog,
        private readonly MediaManagerInterface $media,
    ) {}

    /**
     * Create a product shell, upload the primary thumbnail and any gallery
     * images, and return a fully hydrated DTO.
     *
     * @param  UploadedFile[]  $galleryFiles  Ordered array of gallery image files.
     *                                        Array index becomes the sort_order.
     */
    public function handle(array $data, ?UploadedFile $primaryImage = null, array $galleryFiles = []): ProductDTO
    {
        return DB::transaction(function () use ($data, $primaryImage, $galleryFiles): ProductDTO {
            $primaryMediaId = $this->resolveMediaId($primaryImage, $data['primary_media_id'] ?? null);
            $galleryMediaIds = $data['gallery_media_ids'] ?? [];

            $product = $this->catalog->createProduct([
                'category_id' => $data['category_id'] ?? null,
                'title' => $data['title'],
                'slug' => $data['slug'] ?? Str::slug($data['title']),
                'description' => $data['description'] ?? null,
                'features' => $data['features'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'primary_media_id' => $primaryMediaId,
            ]);

            foreach ($galleryMediaIds as $sortOrder => $mediaId) {
                $this->catalog->addProductImage($product->id, (int) $mediaId, $sortOrder);
            }

            foreach ($galleryFiles as $sortOrder => $file) {
                $galleryMediaId = $this->media->upload($file, 'products/gallery')->id;
                $this->catalog->addProductImage($product->id, $galleryMediaId, $sortOrder);
            }

            return $this->catalog->findProductAdmin($product->id)
                ?? throw new \RuntimeException("Product #{$product->id} could not be loaded after creation.");
        });
    }

    private function resolveMediaId(?UploadedFile $upload, mixed $existingId): ?int
    {
        if ($upload !== null) {
            return $this->media->upload($upload, 'products')->id;
        }

        return $existingId !== null ? (int) $existingId : null;
    }
}
