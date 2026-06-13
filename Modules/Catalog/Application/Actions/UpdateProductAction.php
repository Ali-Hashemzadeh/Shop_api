<?php

namespace Modules\Catalog\Application\Actions;

use Illuminate\Http\UploadedFile;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\ProductDTO;
use Modules\Media\Domain\Contracts\MediaManagerInterface;

class UpdateProductAction
{
    public function __construct(
        private readonly CatalogManagerInterface $catalog,
        private readonly MediaManagerInterface   $media,
    ) {}

    public function handle(int $id, array $data, ?UploadedFile $primaryImage = null): ProductDTO
    {
        if ($primaryImage) {
            $mediaDto                  = $this->media->upload($primaryImage, 'products');
            $data['primary_media_id']  = $mediaDto->id;
        }

        return $this->catalog->updateProduct($id, $data);
    }
}
