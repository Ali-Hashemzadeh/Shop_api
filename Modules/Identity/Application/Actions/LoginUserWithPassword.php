<?php

namespace Modules\Identity\Application\Actions;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Infrastructure\Persistence\Repositories\UserRepositoryInterface;


class LoginUserWithPassword
{
    public function __construct(
        private readonly UserRepositoryInterface $users
    ) {
    }

    public function handle(array $data): array
    {
        $mode = config('identity.login_field', 'both');

        $user = $this->users->findForLogin(
            login: $data['login'],
            mode: $mode
        );

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['Invalid credentials.'],
            ]);
        }

        $token = $user->createToken($data['device_name'])->plainTextToken;

        return [
            'message' => 'Logged in successfully.',
            'user' => $user,
            'token' => $token,
        ];
    }
}
