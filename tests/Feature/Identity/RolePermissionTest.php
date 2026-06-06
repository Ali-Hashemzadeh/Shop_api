<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Domain\Models\User;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedIdentityRolesAndPermissions();
    }

    public function test_customer_role_has_own_permissions_but_not_any_permissions(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $this->assertTrue($customer->can('profile.view-own'));
        $this->assertTrue($customer->can('profile.update-own'));
        $this->assertTrue($customer->can('address.view-own'));
        $this->assertTrue($customer->can('address.create-own'));
        $this->assertTrue($customer->can('address.update-own'));
        $this->assertTrue($customer->can('address.delete-own'));
        $this->assertTrue($customer->can('address.set-default-own'));

        $this->assertFalse($customer->can('profile.view-any'));
        $this->assertFalse($customer->can('profile.update-any'));
        $this->assertFalse($customer->can('address.view-any'));
        $this->assertFalse($customer->can('address.update-any'));
        $this->assertFalse($customer->can('address.delete-any'));
    }

    public function test_admin_role_has_management_permissions(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertTrue($admin->can('profile.view-any'));
        $this->assertTrue($admin->can('profile.update-any'));
        $this->assertTrue($admin->can('address.view-any'));
        $this->assertTrue($admin->can('address.update-any'));
        $this->assertTrue($admin->can('address.delete-any'));
    }
}
