<?php

namespace Modules\Catalog\Application\Actions;

use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;

class DeleteBrandAction
{
    public function __construct(private readonly CatalogManagerInterface $catalog) {}

    public function handle(int $id): void
    {
        $this->catalog->deleteBrand($id);
    }
}
