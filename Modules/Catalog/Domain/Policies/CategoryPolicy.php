<?php

namespace Modules\Catalog\Domain\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;

class CategoryPolicy
{
    public function create(Authorizable $user): bool
    {
        return $user->can('catalog.category.create');
    }

    public function update(Authorizable $user): bool
    {
        return $user->can('catalog.category.update');
    }

    public function delete(Authorizable $user): bool
    {
        return $user->can('catalog.category.delete');
    }
}
