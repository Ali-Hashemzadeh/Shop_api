<?php

namespace Modules\Identity\Application\Actions;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Domain\Models\User;

class LoginUserWithPassword
{
    public function handle(array $data): array
    {
        $mode = config('identity.login_field', 'both');
        $login = trim($data['login']);

        $query = User::query();

        if ($mode === 'email') {
            $query->where('email', $login);
        } elseif ($mode === 'phone') {
            $query->where('phone', $login);
        } else {
            $query->where(function ($q) use ($login) {
                $q->where('email', $login)
                    ->orWhere('phone', $login);
            });
        }

        $user = $query->first();

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
