<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Persistence\Repositories\UserRepositoryInterface;

/**
 * Create a shopper or a delivery worker on an admin's behalf.
 *
 * The account is deliberately credential-less: no password, no temporary secret
 * to leak or expire. The phone number is the credential — the person signs in
 * through the ordinary OTP flow and may set a password afterwards themselves.
 */
class CreateUserByAdmin
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly GrantDeliveryRole $grantDeliveryRole,
    ) {}

    /**
     * @param  array{name: string, last_name?: string|null, phone: string, email?: string|null, role: string}  $data
     */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = $this->users->create([
                'name' => $data['name'],
                'last_name' => $data['last_name'] ?? null,
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
            ]);

            // Every account starts as a shopper. A delivery worker is a shopper who
            // also delivers, so the courier case *adds* to that rather than
            // replacing it — GrantDeliveryRole enforces the same composition.
            $user->assignRole('customer');

            if (($data['role'] ?? 'customer') === 'delivery') {
                $user = $this->grantDeliveryRole->handle($user);
            }

            return $user->fresh();
        });
    }
}
