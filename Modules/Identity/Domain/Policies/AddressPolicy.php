<?php

namespace Modules\Identity\Domain\Policies;

use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Domain\Models\User;

class AddressPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('address.view-any');
    }

    public function viewOwn(User $user): bool
    {
        return $user->can('address.view-own');
    }

    public function view(User $user, Address $address): bool
    {
        if ($user->can('address.view-any')) {
            return true;
        }

        return $user->can('address.view-own') && (int) $user->id === (int) $address->user_id;
    }

    public function create(User $user): bool
    {
        return $user->can('address.create-own');
    }

    public function update(User $user, Address $address): bool
    {
        if ($user->can('address.update-any')) {
            return true;
        }

        return $user->can('address.update-own') && (int) $user->id === (int) $address->user_id;
    }

    public function delete(User $user, Address $address): bool
    {
        if ($user->can('address.delete-any')) {
            return true;
        }

        return $user->can('address.delete-own') && (int) $user->id === (int) $address->user_id;
    }

    public function setDefaultShipping(User $user, Address $address): bool
    {
        return $user->can('address.set-default-own') && (int) $user->id === (int) $address->user_id;
    }
}
