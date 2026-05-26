<?php

namespace Modules\Identity\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Persistence\Repositories\AddressRepositoryInterface;


class CreateAddress
{
    public function __construct(
        private readonly AddressRepositoryInterface $addresses
    ) {
    }

    public function handle(User $user, array $data): Address
    {
        return DB::transaction(function () use ($user, $data) {
            if (($data['is_default_shipping'] ?? false) === true) {
                $this->addresses->clearDefaultShippingForUser($user);
            }

            $address = $this->addresses->createForUser($user, $data);

            return $this->addresses->refreshWithRelations($address);
        });
    }
}
