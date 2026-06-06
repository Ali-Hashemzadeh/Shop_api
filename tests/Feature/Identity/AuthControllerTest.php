<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Domain\Models\User;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedIdentityRolesAndPermissions();
    }

    public function test_user_can_register_with_email_when_email_mode_is_enabled(): void
    {
        config()->set('identity.login_field', 'email');

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Hojjat',
            'email' => 'hojjat@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'device_name' => 'phpunit',
        ]);

        $response
            ->assertCreated()
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'name', 'email'],
                'token',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'hojjat@example.com',
        ]);
    }

    public function test_user_can_register_with_phone_when_phone_mode_is_enabled(): void
    {
        config()->set('identity.login_field', 'phone');

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Hojjat',
            'phone' => '09123456789',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'device_name' => 'phpunit',
        ]);

        $response
            ->assertCreated()
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'name', 'phone'],
                'token',
            ]);

        $this->assertDatabaseHas('users', [
            'phone' => '09123456789',
        ]);
    }

    public function test_user_can_register_with_email_or_phone_when_both_mode_is_enabled(): void
    {
        config()->set('identity.login_field', 'both');

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Hojjat',
            'email' => 'hojjat@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'device_name' => 'phpunit',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'hojjat@example.com',
        ]);
    }

    public function test_user_can_login_with_email_in_both_mode(): void
    {
        config()->set('identity.login_field', 'both');

        $user = User::factory()->create([
            'email' => 'hojjat@example.com',
            'phone' => '09123456789',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'login' => 'hojjat@example.com',
            'password' => 'password123',
            'device_name' => 'phpunit',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure([
                'message',
                'user',
                'token',
            ]);
    }

    public function test_user_can_login_with_phone_in_both_mode(): void
    {
        config()->set('identity.login_field', 'both');

        $user = User::factory()->create([
            'email' => 'hojjat@example.com',
            'phone' => '09123456789',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'login' => '09123456789',
            'password' => 'password123',
            'device_name' => 'phpunit',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure([
                'message',
                'user',
                'token',
            ]);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        config()->set('identity.login_field', 'both');

        User::factory()->create([
            'email' => 'hojjat@example.com',
            'phone' => '09123456789',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'login' => 'hojjat@example.com',
            'password' => 'wrong-password',
            'device_name' => 'phpunit',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['login']);
    }

    public function test_authenticated_user_can_get_profile(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/me');

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/logout');

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Logged out successfully.',
            ]);
    }
}
