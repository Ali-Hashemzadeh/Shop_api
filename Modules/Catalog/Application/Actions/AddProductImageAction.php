<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Actions;

use Illuminate\Http\UploadedFile;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\ProductImageDTO;
use Modules\Media\Domain\Contracts\MediaManagerInterface;

class AddProductImageAction
{
    public function __construct(
        private readonly CatalogManagerInterface $catalog,
        private readonly MediaManagerInterface $media,
    ) {}

    public function handle(int $productId, array $data, ?UploadedFile $image = null): ProductImageDTO
    {
        $mediaId = $image !== null
            ? $this->media->upload($image, 'products/gallery')->id
            : (int) $data['media_id'];

        return $this->catalog->addProductImage(
            $productId,
            $mediaId,
            (int) ($data['sort_order'] ?? 0),
        );
    }
}
