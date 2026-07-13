<?php

namespace Modules\Catalog\Application\Actions;

use Illuminate\Http\UploadedFile;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\BrandDTO;
use Modules\Media\Domain\Contracts\MediaManagerInterface;

class UpdateBrandAction
{
    public function __construct(
        private readonly CatalogManagerInterface $catalog,
        private readonly MediaManagerInterface $media,
    ) {}

    public function handle(int $id, array $data, ?UploadedFile $image = null): BrandDTO
    {
        if ($image !== null) {
            $data['media_id'] = $this->media->upload($image, 'brands')->id;
        }

        return $this->catalog->updateBrand($id, $data);
    }
}
