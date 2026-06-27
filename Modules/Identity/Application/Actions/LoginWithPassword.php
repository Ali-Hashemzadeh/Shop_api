<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Actions;

use Illuminate\Support\Facades\Hash;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Persistence\Repositories\UserRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Password authentication for returning users. Failure is intentionally opaque
 * — an unknown phone, an account with no password set, and a wrong password all
 * yield the same generic 401 so the endpoint never leaks account existence.
 */
class LoginWithPassword
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    /**
     * @return array{message: string, user: User, token: string}
     */
    public function handle(array $data): array
    {
        $user = $this->users->findByPhone($data['phone_number']);

        $isValid = $user !== null
            && $user->password !== null
            && Hash::check($data['password'], $user->password);

        if (! $isValid) {
            throw new HttpException(401, 'Invalid credentials.');
        }

        $token = $user->createToken($data['device_name'] ?? 'password-login')->plainTextToken;

        return [
            'message' => 'Logged in successfully.',
            'user' => $user,
            'token' => $token,
        ];
    }
}
