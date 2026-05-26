<?php

namespace Modules\Identity\Application\Actions;

use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Persistence\Repositories\UserRepositoryInterface;

class UpdateProfile
{
    public function __construct(
        private readonly UserRepositoryInterface $users
    ) {
    }

    public function handle(User $user, array $data): User
    {
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = $data['name'];
        }

        if (array_key_exists('email', $data)) {
            $attributes['email'] = $data['email'];
        }

        if (array_key_exists('phone', $data)) {
            $attributes['phone'] = $data['phone'];
        }

        if (! empty($attributes)) {
            $this->users->update($user, $attributes);
        }

        return $this->users->refresh($user);
    }
}
