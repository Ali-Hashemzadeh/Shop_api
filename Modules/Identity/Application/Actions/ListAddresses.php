<?php

namespace Modules\Identity\Application\Actions;

use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Persistence\Repositories\AddressRepositoryInterface;

class ListAddresses
{
    public function __construct(
        private readonly AddressRepositoryInterface $addresses
    ) {}

    public function handle(User $user): \Illuminate\Support\Collection
    {
        return $this->addresses->listForUser($user);
    }
}
