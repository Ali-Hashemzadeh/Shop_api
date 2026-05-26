<?php

namespace Modules\Identity\Application\Actions;

use Illuminate\Database\Eloquent\Collection;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Persistence\Repositories\AddressRepositoryInterface;


class ListAddresses
{
    public function __construct(
        private readonly AddressRepositoryInterface $addresses
    ) {
    }

    public function handle(User $user): Collection
    {
        return $this->addresses->getUserAddresses($user);
    }
}
