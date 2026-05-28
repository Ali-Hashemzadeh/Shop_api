<?php

namespace Modules\Identity\Domain\Policies;



use Modules\Identity\Domain\Models\User;

class ProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('profile.view-any');
    }

    public function view(User $user, User $profile): bool
    {
        if ($user->can('profile.view-any')) {
            return true;
        }

        return $user->can('profile.view-own') && $user->id === $profile->id;
    }

    public function update(User $user, User $profile): bool
    {
        if ($user->can('profile.update-any')) {
            return true;
        }

        return $user->can('profile.update-own') && $user->id === $profile->id;
    }

    public function delete(User $user, User $profile): bool
    {
        if ($user->id === $profile->id) {
            return false;
        }

        return $user->can('profile.delete-any');
    }
}
