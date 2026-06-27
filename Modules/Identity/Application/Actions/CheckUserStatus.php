<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Actions;

use Modules\Identity\Infrastructure\Persistence\Repositories\UserRepositoryInterface;

/**
 * Split-auth onboarding gate: decide which authentication methods a phone
 * number may use. Unknown numbers must verify ownership via OTP first (no
 * password path yet); known numbers may choose password or OTP.
 */
class CheckUserStatus
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    /**
     * @return array{is_new_user: bool, allowed_methods: list<string>}
     */
    public function handle(array $data): array
    {
        $isNewUser = $this->users->findByPhone($data['phone_number']) === null;

        return [
            'is_new_user' => $isNewUser,
            'allowed_methods' => $isNewUser ? ['otp'] : ['password', 'otp'],
        ];
    }
}
