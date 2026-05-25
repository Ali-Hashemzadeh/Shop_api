<?php

namespace Modules\Identity\Application\Actions;

use Illuminate\Support\Facades\Hash;
use Modules\Identity\Domain\Repositories\UserRepositoryInterface;

class RegisterUser
{
    public function __construct(
        private readonly UserRepositoryInterface $users
    ) {
    }

    public function handle(array $data): array
    {
        $user = $this->users->create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
        ]);

        $token = $user->createToken($data['device_name'])->plainTextToken;

        return [
            'message' => 'Registered successfully.',
            'user' => $user,
            'token' => $token,
        ];
    }
}
