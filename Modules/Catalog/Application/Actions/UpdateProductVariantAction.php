<?php

namespace Modules\Catalog\Application\Actions;

use InvalidArgumentException;
use Illuminate\Http\UploadedFile;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\ProductVariantDTO;
use Modules\Media\Domain\Contracts\MediaManagerInterface;

class UpdateProductVariantAction
{
    public function __construct(
        private readonly CatalogManagerInterface $catalog,
        private readonly MediaManagerInterface   $media,
    ) {}

    public function handle(int $variantId, array $data, ?UploadedFile $image = null): ProductVariantDTO
    {
        if (isset($data['base_price']) && ! is_int($data['base_price'])) {
            throw new InvalidArgumentException('base_price must be an integer representing cents.');
        }

        if (isset($data['compare_at_price']) && $data['compare_at_price'] !== null && ! is_int($data['compare_at_price'])) {
            throw new InvalidArgumentException('compare_at_price must be an integer representing cents.');
        }

        if ($image) {
            $mediaDto        = $this->media->upload($image, 'variants');
            $data['media_id'] = $mediaDto->id;
        }

        return $this->catalog->updateProductVariant($variantId, $data);
    }
}
