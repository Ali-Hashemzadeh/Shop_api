<?php

namespace Modules\Identity\Application\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Infrastructure\Persistence\Repositories\UserRepositoryInterface;

/**
 * Verify a one-time passcode and, on success, mint a Sanctum token. The code is
 * single-use: it is cleared only on a successful verification, so a wrong guess
 * does not burn the still-valid code before it expires.
 */
class VerifyOtp
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    public function handle(array $data): array
    {
        $user = $this->users->findByPhone($data['phone']);

        $isValid = $user !== null
            && $user->otp_code !== null
            && $user->otp_expires_at !== null
            && Carbon::now()->lessThanOrEqualTo($user->otp_expires_at)
            && Hash::check($data['code'], $user->otp_code);

        if (! $isValid) {
            throw ValidationException::withMessages([
                'code' => ['The verification code is invalid or has expired.'],
            ]);
        }

        // Consume the code so it cannot be replayed.
        $this->users->update($user, [
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

        $token = $user->createToken($data['device_name'])->plainTextToken;

        return [
            'message' => 'Logged in successfully.',
            'user' => $user,
            'token' => $token,
        ];
    }
}
