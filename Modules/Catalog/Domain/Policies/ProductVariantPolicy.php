<?php

namespace Modules\Catalog\Domain\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;

class ProductVariantPolicy
{
    public function create(Authorizable $user): bool
    {
        return $user->can('catalog.variant.create');
    }

    public function update(Authorizable $user): bool
    {
        return $user->can('catalog.variant.update');
    }

    public function delete(Authorizable $user): bool
    {
        return $user->can('catalog.variant.delete');
    }
}
