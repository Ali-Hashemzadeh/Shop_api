<?php

namespace Modules\Catalog\Domain\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;

class BrandPolicy
{
    public function create(Authorizable $user): bool
    {
        return $user->can('catalog.brand.create');
    }

    public function update(Authorizable $user): bool
    {
        return $user->can('catalog.brand.update');
    }

    public function delete(Authorizable $user): bool
    {
        return $user->can('catalog.brand.delete');
    }
}
