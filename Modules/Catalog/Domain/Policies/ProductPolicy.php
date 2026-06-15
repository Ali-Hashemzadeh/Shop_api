<?php

namespace Modules\Catalog\Domain\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;

class ProductPolicy
{
    public function viewAdmin(Authorizable $user): bool
    {
        return $user->can('catalog.product.view-admin');
    }

    public function create(Authorizable $user): bool
    {
        return $user->can('catalog.product.create');
    }

    public function update(Authorizable $user): bool
    {
        return $user->can('catalog.product.update');
    }

    public function delete(Authorizable $user): bool
    {
        return $user->can('catalog.product.delete');
    }
}
