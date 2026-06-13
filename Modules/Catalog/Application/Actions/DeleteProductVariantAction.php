<?php

namespace Modules\Catalog\Application\Actions;

use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;

class DeleteProductVariantAction
{
    public function __construct(private readonly CatalogManagerInterface $catalog) {}

    public function handle(int $variantId): void
    {
        $this->catalog->deleteProductVariant($variantId);
    }
}
