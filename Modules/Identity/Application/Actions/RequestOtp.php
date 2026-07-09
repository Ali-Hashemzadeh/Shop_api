<?php

namespace Modules\Identity\Application\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Modules\Identity\Domain\Contracts\OtpSenderInterface;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Persistence\Repositories\UserRepositoryInterface;

/**
 * Unified passwordless entry point: issue a one-time passcode for a phone
 * number, creating the account on first contact (sign-up == login).
 */
class RequestOtp
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly OtpSenderInterface $sender,
    ) {}

    public function handle(array $data): array
    {
        $phone = $data['phone'];

        $existing = $this->users->findByPhone($phone);
        $isNewUser = $existing === null;
        $user = $existing ?? $this->createUser($phone, $data['name'] ?? null, $data['last_name'] ?? null);

        $code = $this->generateCode();

        $this->users->update($user, [
            'otp_code' => Hash::make($code),
            'otp_expires_at' => Carbon::now()->addMinutes($this->ttlMinutes()),
        ]);

        $this->sender->send($phone, $code);

        return [
            'message' => 'Verification code sent.',
            'expires_in' => $this->ttlMinutes() * 60,
            'is_new_user' => $isNewUser,
        ];
    }

    private function createUser(string $phone, ?string $name, ?string $lastName): User
    {
        $user = $this->users->create([
            'phone' => $phone,
            'name' => $name,
            'last_name' => $lastName,
        ]);

        $user->assignRole('customer');

        return $user;
    }

    private function generateCode(): string
    {
        $length = max(4, (int) config('identity.otp.length', 5));

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('identity.otp.ttl_minutes', 2));
    }
}
