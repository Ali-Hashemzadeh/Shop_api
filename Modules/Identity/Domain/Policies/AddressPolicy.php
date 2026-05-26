<?php

namespace Modules\Identity\Domain\Policies;

use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Domain\Models\User;

class AddressPolicy
{
    public function view(User $user, Address $address): bool
    {
        return $user->id === $address->user_id;
    }

    public function update(User $user, Address $address): bool
    {
        return $user->id === $address->user_id;
    }

    public function delete(User $user, Address $address): bool
    {
        return $user->id === $address->user_id;
    }
}
