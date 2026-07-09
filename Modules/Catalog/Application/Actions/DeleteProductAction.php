<?php

namespace Modules\Catalog\Application\Actions;

use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;

class DeleteProductAction
{
    public function __construct(private readonly CatalogManagerInterface $catalog) {}

    public function handle(string $uuid): void
    {
        $this->catalog->deleteProduct($uuid);
    }
}
