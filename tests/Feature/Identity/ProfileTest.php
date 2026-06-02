<?php

namespace Tests\Feature\Identity;

use Modules\Identity\Domain\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedIdentityRolesAndPermissions();
    }

    public function test_authenticated_user_can_view_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Ali Rezaei',
            'email' => 'ali@example.com',
            'phone' => '09120000001',
        ]);

        $this->actingAsCustomer($user);

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Ali Rezaei')
            ->assertJsonPath('data.email', 'ali@example.com')
            ->assertJsonPath('data.phone', '09120000001');
    }

    public function test_authenticated_user_can_update_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'phone' => '09120000001',
        ]);

        $this->actingAsCustomer($user);

        $this->patchJson('/api/v1/profile', [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'phone' => '09120000002',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Profile updated successfully.')
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.phone', '09120000002');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'email' => 'new@example.com',
            'phone' => '09120000002',
        ]);
    }

    public function test_guest_cannot_access_profile(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();
        $this->patchJson('/api/v1/profile', [
            'name' => 'Test',
        ])->assertUnauthorized();
    }

    public function test_user_cannot_update_profile_with_taken_email(): void
    {
        $user = User::factory()->create([
            'email' => 'first@example.com',
        ]);

        $otherUser = User::factory()->create([
            'email' => 'second@example.com',
        ]);

        $this->actingAsCustomer($user);

        $this->patchJson('/api/v1/profile', [
            'email' => 'second@example.com',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
