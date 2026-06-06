<?php

namespace Tests\Feature\Identity;

use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Domain\Models\City;
use Modules\Identity\Domain\Models\Province;
use Modules\Identity\Domain\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedIdentityRolesAndPermissions();
    }

    public function test_authenticated_user_can_list_own_addresses(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $province = Province::factory()->create();
        $city = City::factory()->create(['province_id' => $province->id]);

        Address::factory()->count(2)->create([
            'user_id' => $user->id,
            'province_id' => $province->id,
            'city_id' => $city->id,
        ]);

        Address::factory()->create([
            'user_id' => $otherUser->id,
            'province_id' => $province->id,
            'city_id' => $city->id,
        ]);

        $this->actingAsCustomer($user);

        $this->getJson('/api/v1/addresses')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_authenticated_user_can_create_address(): void
    {
        $user = User::factory()->create();
        $province = Province::factory()->create();
        $city = City::factory()->create(['province_id' => $province->id]);

        $this->actingAsCustomer($user);

        $payload = [
            'title' => 'Home',
            'province_id' => $province->id,
            'city_id' => $city->id,
            'postal_code' => '1234567890',
            'address' => 'Test street, alley 1, plaque 10',
            'is_default_shipping' => true,
        ];

        $this->postJson('/api/v1/addresses', $payload)
            ->assertCreated()
            ->assertJsonPath('message', 'Address created successfully.')
            ->assertJsonPath('data.title', 'Home')
            ->assertJsonPath('data.province_id', $province->id)
            ->assertJsonPath('data.city_id', $city->id)
            ->assertJsonPath('data.is_default_shipping', true);

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'title' => 'Home',
            'province_id' => $province->id,
            'city_id' => $city->id,
            'postal_code' => '1234567890',
            'address' => 'Test street, alley 1, plaque 10',
            'is_default_shipping' => true,
        ]);
    }

    public function test_authenticated_user_can_show_own_address(): void
    {
        $user = User::factory()->create();
        $province = Province::factory()->create();
        $city = City::factory()->create(['province_id' => $province->id]);

        $address = Address::factory()->create([
            'user_id' => $user->id,
            'province_id' => $province->id,
            'city_id' => $city->id,
        ]);

        $this->actingAsCustomer($user);

        $this->getJson("/api/v1/addresses/{$address->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $address->id);
    }

    public function test_user_cannot_show_another_users_address(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $province = Province::factory()->create();
        $city = City::factory()->create(['province_id' => $province->id]);

        $address = Address::factory()->create([
            'user_id' => $otherUser->id,
            'province_id' => $province->id,
            'city_id' => $city->id,
        ]);

        $this->actingAsCustomer($user);

        $this->getJson("/api/v1/addresses/{$address->id}")
            ->assertForbidden();
    }

    public function test_admin_can_show_another_users_address(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $province = Province::factory()->create();
        $city = City::factory()->create(['province_id' => $province->id]);

        $address = Address::factory()->create([
            'user_id' => $owner->id,
            'province_id' => $province->id,
            'city_id' => $city->id,
        ]);

        $this->actingAsAdmin($admin);

        $this->getJson("/api/v1/addresses/{$address->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $address->id);
    }

    public function test_authenticated_user_can_update_own_address(): void
    {
        $user = User::factory()->create();
        $province = Province::factory()->create();
        $city = City::factory()->create(['province_id' => $province->id]);

        $address = Address::factory()->create([
            'user_id' => $user->id,
            'province_id' => $province->id,
            'city_id' => $city->id,
            'title' => 'Home',
            'postal_code' => '1111111111',
            'address' => 'Old Address',
            'is_default_shipping' => false,
        ]);

        $this->actingAsCustomer($user);

        $this->patchJson("/api/v1/addresses/{$address->id}", [
            'title' => 'Office',
            'postal_code' => '2222222222',
            'address' => 'New Address',
            'is_default_shipping' => true,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Address updated successfully.')
            ->assertJsonPath('data.title', 'Office')
            ->assertJsonPath('data.postal_code', '2222222222')
            ->assertJsonPath('data.address', 'New Address')
            ->assertJsonPath('data.is_default_shipping', true);

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'title' => 'Office',
            'postal_code' => '2222222222',
            'address' => 'New Address',
            'is_default_shipping' => true,
        ]);
    }

    public function test_customer_cannot_update_another_users_address(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $province = Province::factory()->create();
        $city = City::factory()->create(['province_id' => $province->id]);

        $address = Address::factory()->create([
            'user_id' => $otherUser->id,
            'province_id' => $province->id,
            'city_id' => $city->id,
            'title' => 'Home',
        ]);

        $this->actingAsCustomer($user);

        $this->patchJson("/api/v1/addresses/{$address->id}", [
            'title' => 'Office',
        ])->assertForbidden();

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'title' => 'Home',
        ]);
    }

    public function test_admin_can_update_another_users_address(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $province = Province::factory()->create();
        $city = City::factory()->create(['province_id' => $province->id]);

        $address = Address::factory()->create([
            'user_id' => $owner->id,
            'province_id' => $province->id,
            'city_id' => $city->id,
            'title' => 'Home',
        ]);

        $this->actingAsAdmin($admin);

        $this->patchJson("/api/v1/addresses/{$address->id}", [
            'title' => 'Office',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Office');
    }

    public function test_authenticated_user_can_delete_own_address(): void
    {
        $user = User::factory()->create();
        $province = Province::factory()->create();
        $city = City::factory()->create(['province_id' => $province->id]);

        $address = Address::factory()->create([
            'user_id' => $user->id,
            'province_id' => $province->id,
            'city_id' => $city->id,
        ]);

        $this->actingAsCustomer($user);

        $this->deleteJson("/api/v1/addresses/{$address->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Address deleted successfully.');

        $this->assertDatabaseMissing('addresses', [
            'id' => $address->id,
        ]);
    }

    public function test_customer_cannot_delete_another_users_address(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $province = Province::factory()->create();
        $city = City::factory()->create(['province_id' => $province->id]);

        $address = Address::factory()->create([
            'user_id' => $otherUser->id,
            'province_id' => $province->id,
            'city_id' => $city->id,
        ]);

        $this->actingAsCustomer($user);

        $this->deleteJson("/api/v1/addresses/{$address->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
        ]);
    }

    public function test_admin_can_delete_another_users_address(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $province = Province::factory()->create();
        $city = City::factory()->create(['province_id' => $province->id]);

        $address = Address::factory()->create([
            'user_id' => $owner->id,
            'province_id' => $province->id,
            'city_id' => $city->id,
        ]);

        $this->actingAsAdmin($admin);

        $this->deleteJson("/api/v1/addresses/{$address->id}")
            ->assertOk();

        $this->assertDatabaseMissing('addresses', [
            'id' => $address->id,
        ]);
    }

    public function test_authenticated_user_can_set_default_shipping_address(): void
    {
        $user = User::factory()->create();
        $province = Province::factory()->create();
        $city = City::factory()->create(['province_id' => $province->id]);

        $first = Address::factory()->create([
            'user_id' => $user->id,
            'province_id' => $province->id,
            'city_id' => $city->id,
            'is_default_shipping' => true,
        ]);

        $second = Address::factory()->create([
            'user_id' => $user->id,
            'province_id' => $province->id,
            'city_id' => $city->id,
            'is_default_shipping' => false,
        ]);

        $this->actingAsCustomer($user);

        $this->postJson("/api/v1/addresses/{$second->id}/default-shipping")
            ->assertOk()
            ->assertJsonPath('message', 'Default shipping address updated successfully.')
            ->assertJsonPath('data.id', $second->id)
            ->assertJsonPath('data.is_default_shipping', true);

        $this->assertDatabaseHas('addresses', [
            'id' => $first->id,
            'is_default_shipping' => false,
        ]);

        $this->assertDatabaseHas('addresses', [
            'id' => $second->id,
            'is_default_shipping' => true,
        ]);
    }

    public function test_guest_cannot_access_address_endpoints(): void
    {
        $this->getJson('/api/v1/addresses')->assertUnauthorized();
        $this->postJson('/api/v1/addresses', [])->assertUnauthorized();
    }
}
