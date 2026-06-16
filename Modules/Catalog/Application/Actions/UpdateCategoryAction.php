<?php

namespace Modules\Catalog\Application\Actions;

use Illuminate\Http\UploadedFile;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\CategoryDTO;
use Modules\Media\Domain\Contracts\MediaManagerInterface;

class UpdateCategoryAction
{
    public function __construct(
        private readonly CatalogManagerInterface $catalog,
        private readonly MediaManagerInterface $media,
    ) {}

    public function handle(int $id, array $data, ?UploadedFile $image = null): CategoryDTO
    {
        if ($image !== null) {
            $data['media_id'] = $this->media->upload($image, 'categories')->id;
        }

        return $this->catalog->updateCategory($id, $data);
    }
}
