<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Identity\Domain\Contracts\OtpSenderInterface;
use Modules\Identity\Domain\Models\User;
use Tests\TestCase;

class PasswordAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Captures every code handed to the delivery boundary so the test can
     * replay it through the verify endpoint during registration.
     */
    private object $sender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedIdentityRolesAndPermissions();

        $this->sender = new class implements OtpSenderInterface
        {
            /** @var array<int, array{phone: string, code: string}> */
            public array $sent = [];

            public function send(string $phone, string $code): void
            {
                $this->sent[] = ['phone' => $phone, 'code' => $code];
            }
        };

        $this->app->instance(OtpSenderInterface::class, $this->sender);
    }

    private function lastCode(): string
    {
        return $this->sender->sent[array_key_last($this->sender->sent)]['code'];
    }

    // ---- check-user -------------------------------------------------------

    public function test_check_user_reports_a_new_phone_as_otp_only(): void
    {
        $this->postJson('/api/v1/auth/check-user', ['phone_number' => '09123456789'])
            ->assertOk()
            ->assertExactJson([
                'is_new_user' => true,
                'allowed_methods' => ['otp'],
            ]);
    }

    public function test_check_user_reports_an_existing_phone_as_password_and_otp(): void
    {
        User::factory()->create(['phone' => '09123456789']);

        $this->postJson('/api/v1/auth/check-user', ['phone_number' => '09123456789'])
            ->assertOk()
            ->assertExactJson([
                'is_new_user' => false,
                'allowed_methods' => ['password', 'otp'],
            ]);
    }

    public function test_check_user_rejects_an_invalid_phone(): void
    {
        $this->postJson('/api/v1/auth/check-user', ['phone_number' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    // ---- registration with a password ------------------------------------

    public function test_a_new_user_can_register_with_a_password_stored_as_a_hash(): void
    {
        $this->postJson('/api/v1/otp/request', ['phone' => '09123456789'])->assertOk();

        $response = $this->postJson('/api/v1/otp/verify', [
            'phone' => '09123456789',
            'code' => $this->lastCode(),
            'device_name' => 'phpunit',
            'name' => 'Sara Ahmadi',
            'password' => 'super-secret-pw',
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure(['message', 'user' => ['id', 'phone'], 'token']);

        $user = User::where('phone', '09123456789')->first();
        $this->assertSame('Sara Ahmadi', $user->name);
        $this->assertNotNull($user->password);
        // Stored as a hash, never in cleartext, and verifiable.
        $this->assertNotSame('super-secret-pw', $user->password);
        $this->assertTrue(Hash::check('super-secret-pw', $user->password));
    }

    public function test_registration_without_a_password_leaves_it_null(): void
    {
        $this->postJson('/api/v1/otp/request', ['phone' => '09123456789'])->assertOk();

        $this->postJson('/api/v1/otp/verify', [
            'phone' => '09123456789',
            'code' => $this->lastCode(),
            'device_name' => 'phpunit',
        ])->assertOk();

        $this->assertNull(User::where('phone', '09123456789')->first()->password);
    }

    public function test_registration_rejects_a_too_short_password(): void
    {
        $this->postJson('/api/v1/otp/request', ['phone' => '09123456789'])->assertOk();

        $this->postJson('/api/v1/otp/verify', [
            'phone' => '09123456789',
            'code' => $this->lastCode(),
            'device_name' => 'phpunit',
            'password' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    // ---- password login ---------------------------------------------------

    public function test_an_existing_user_can_log_in_with_a_password_and_receive_a_token(): void
    {
        User::factory()->create([
            'phone' => '09123456789',
            'password' => Hash::make('correct-horse'),
        ]);

        $response = $this->postJson('/api/v1/auth/login-password', [
            'phone_number' => '09123456789',
            'password' => 'correct-horse',
            'device_name' => 'phpunit',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.phone', '09123456789')
            ->assertJsonStructure(['message', 'user' => ['id', 'phone'], 'token']);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_password_login_fails_with_a_wrong_password(): void
    {
        User::factory()->create([
            'phone' => '09123456789',
            'password' => Hash::make('correct-horse'),
        ]);

        $this->postJson('/api/v1/auth/login-password', [
            'phone_number' => '09123456789',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_password_login_fails_for_an_unknown_phone(): void
    {
        $this->postJson('/api/v1/auth/login-password', [
            'phone_number' => '09999999999',
            'password' => 'whatever',
        ])
            ->assertStatus(401)
            ->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_password_login_fails_for_an_account_without_a_password(): void
    {
        // OTP-only account: never set a password.
        User::factory()->create([
            'phone' => '09123456789',
            'password' => null,
        ]);

        $this->postJson('/api/v1/auth/login-password', [
            'phone_number' => '09123456789',
            'password' => 'anything',
        ])
            ->assertStatus(401)
            ->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_password_login_requires_a_password(): void
    {
        User::factory()->create(['phone' => '09123456789']);

        $this->postJson('/api/v1/auth/login-password', [
            'phone_number' => '09123456789',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
}
