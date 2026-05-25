<?php


namespace Modules\Identity\Application\Actions;

use Illuminate\Support\Facades\Hash;
use Modules\Identity\Domain\Models\User;

class RegisterUser
{
    public function handle(array $data): array
    {
        $user = User::create([
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
