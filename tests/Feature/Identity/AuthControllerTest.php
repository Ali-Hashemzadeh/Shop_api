<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Domain\Contracts\OtpSenderInterface;
use Modules\Identity\Domain\Models\User;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test double that captures every code handed to the delivery boundary so
     * the test can replay it through the verify endpoint.
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

    public function test_requesting_otp_for_a_new_phone_creates_a_customer_and_sends_a_code(): void
    {
        $response = $this->postJson('/api/v1/otp/request', [
            'phone' => '09123456789',
            'name' => 'Hojjat',
            'last_name' => 'Karimi',
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure(['message', 'expires_in', 'is_new_user'])
            ->assertJsonPath('is_new_user', true);

        $this->assertCount(1, $this->sender->sent);
        $this->assertSame('09123456789', $this->sender->sent[0]['phone']);

        $user = User::where('phone', '09123456789')->first();
        $this->assertNotNull($user);
        $this->assertSame('Hojjat', $user->name);
        $this->assertSame('Karimi', $user->last_name);
        $this->assertTrue($user->hasRole('customer'));
        $this->assertNotNull($user->otp_code);
        $this->assertNotNull($user->otp_expires_at);
    }

    public function test_requesting_otp_for_an_existing_user_does_not_create_a_duplicate(): void
    {
        $user = User::factory()->create(['phone' => '09123456789']);

        $this->postJson('/api/v1/otp/request', ['phone' => '09123456789'])
            ->assertOk();

        $this->assertSame(1, User::where('phone', '09123456789')->count());
        $this->assertSame($user->id, User::where('phone', '09123456789')->first()->id);
    }

    public function test_requesting_otp_for_an_existing_user_returns_is_new_user_false(): void
    {
        User::factory()->create(['phone' => '09123456789']);

        $this->postJson('/api/v1/otp/request', ['phone' => '09123456789'])
            ->assertOk()
            ->assertJsonPath('is_new_user', false);
    }

    public function test_requesting_otp_rejects_an_invalid_phone(): void
    {
        $this->postJson('/api/v1/otp/request', ['phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $this->assertCount(0, $this->sender->sent);
    }

    public function test_user_can_verify_otp_and_receive_a_token(): void
    {
        $this->postJson('/api/v1/otp/request', ['phone' => '09123456789'])->assertOk();

        $response = $this->postJson('/api/v1/otp/verify', [
            'phone' => '09123456789',
            'code' => $this->lastCode(),
            'device_name' => 'phpunit',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.phone', '09123456789')
            ->assertJsonStructure(['message', 'user' => ['id', 'phone'], 'token']);

        $user = User::where('phone', '09123456789')->first();
        $this->assertNull($user->otp_code);
        $this->assertNull($user->otp_expires_at);
    }

    public function test_verify_fails_with_a_wrong_code_without_consuming_it(): void
    {
        $this->postJson('/api/v1/otp/request', ['phone' => '09123456789'])->assertOk();

        $this->postJson('/api/v1/otp/verify', [
            'phone' => '09123456789',
            'code' => '00000',
            'device_name' => 'phpunit',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        // The real code remains usable after a failed guess.
        $this->postJson('/api/v1/otp/verify', [
            'phone' => '09123456789',
            'code' => $this->lastCode(),
            'device_name' => 'phpunit',
        ])->assertOk();
    }

    public function test_verify_fails_with_an_expired_code(): void
    {
        User::factory()->create([
            'phone' => '09123456789',
            'otp_code' => Hash::make('12345'),
            'otp_expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/otp/verify', [
            'phone' => '09123456789',
            'code' => '12345',
            'device_name' => 'phpunit',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_verify_fails_for_an_unknown_phone(): void
    {
        $this->postJson('/api/v1/otp/verify', [
            'phone' => '09999999999',
            'code' => '12345',
            'device_name' => 'phpunit',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_a_verified_code_cannot_be_reused(): void
    {
        $this->postJson('/api/v1/otp/request', ['phone' => '09123456789'])->assertOk();
        $code = $this->lastCode();

        $this->postJson('/api/v1/otp/verify', [
            'phone' => '09123456789',
            'code' => $code,
            'device_name' => 'phpunit',
        ])->assertOk();

        $this->postJson('/api/v1/otp/verify', [
            'phone' => '09123456789',
            'code' => $code,
            'device_name' => 'phpunit',
        ])->assertStatus(422);
    }

    public function test_authenticated_user_can_get_profile(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out successfully.']);
    }
}
