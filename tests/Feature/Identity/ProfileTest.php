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

    public function test_customer_cannot_view_another_users_profile(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAsCustomer($user);

        $this->getJson("/api/v1/profile/show/{$otherUser->id}")
            ->assertForbidden();
    }

    public function test_admin_can_view_another_users_profile(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create([
            'name' => 'Target User',
        ]);

        $this->actingAsAdmin($admin);

        $this->getJson("/api/v1/profile/show/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Target User');
    }

    public function test_customer_cannot_update_another_users_profile(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create([
            'name' => 'Original Name',
        ]);

        $this->actingAsCustomer($user);

        $this->patchJson("/api/v1/profile/{$otherUser->id}", [
            'name' => 'Changed Name',
        ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $otherUser->id,
            'name' => 'Original Name',
        ]);
    }

    public function test_admin_can_update_another_users_profile(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create([
            'name' => 'Original Name',
        ]);

        $this->actingAsAdmin($admin);

        $this->patchJson("/api/v1/profile/{$user->id}", [
            'name' => 'Changed Name',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Changed Name');
    }

    public function test_admin_cannot_delete_own_profile(): void
    {
        $admin = User::factory()->create();

        $this->actingAsAdmin($admin);

        $this->deleteJson("/api/v1/profile/{$admin->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
        ]);
    }
}
