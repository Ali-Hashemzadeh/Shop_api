<?php

namespace Modules\Identity\Application\Actions;

use Illuminate\Support\Collection;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Persistence\Repositories\AddressRepositoryInterface;

class ListAddresses
{
    public function __construct(
        private readonly AddressRepositoryInterface $addresses
    ) {}

    /** $search is an optional exact `bda-XXXXXX` code; ownership is always enforced. */
    public function handle(User $user, ?string $search = null): Collection
    {
        return $this->addresses->listForUser($user, $search);
    }
}
