<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Domain\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The generic RBAC admin surface: role/permission catalogue reads, additive
 * permission grants on roles, and additive role grants on users.
 *
 * Two invariants run through every test: authorization is permission-based
 * (`rbac.view` / `rbac.manage`), and the `admin` role is untouchable — never
 * assignable, never revocable, its bundle never editable.
 */
class RbacManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedIdentityRolesAndPermissions();
    }

    // ── Catalogue reads (`rbac.view`) ────────────────────────────────────────

    public function test_admin_can_list_roles_with_counts(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/roles')->assertOk();

        $roles = collect($response->json('data'));

        $this->assertTrue($roles->pluck('name')->contains('admin'));
        $this->assertTrue($roles->pluck('name')->contains('customer'));
        $this->assertTrue($roles->pluck('name')->contains('delivery'));

        $admin = $roles->firstWhere('name', 'admin');
        $this->assertArrayHasKey('permissions_count', $admin);
        $this->assertArrayHasKey('users_count', $admin);
        // The acting admin is the only account holding the role.
        $this->assertSame(1, $admin['users_count']);
        $this->assertGreaterThan(0, $admin['permissions_count']);
    }

    public function test_roles_list_is_paginated(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/roles?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('meta.per_page'));
        $this->assertGreaterThanOrEqual(3, $response->json('meta.total'));
    }

    public function test_admin_can_view_a_role_with_its_permissions(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/roles/customer')->assertOk();

        $this->assertSame('customer', $response->json('data.name'));
        $this->assertContains('profile.view-own', $response->json('data.permissions'));
        $this->assertNotContains('rbac.manage', $response->json('data.permissions'));
    }

    public function test_unknown_role_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/roles/ghost')->assertNotFound();
    }

    public function test_admin_can_list_permissions_with_search_and_group_filters(): void
    {
        $this->actingAsAdmin();

        $all = $this->getJson('/api/v1/admin/permissions?per_page=100')->assertOk();
        $this->assertContains('rbac.manage', collect($all->json('data'))->pluck('name'));
        $this->assertSame('address', $all->json('data.0.group'));

        $grouped = $this->getJson('/api/v1/admin/permissions?group=address&per_page=100')->assertOk();
        $groupedNames = collect($grouped->json('data'))->pluck('name');
        $this->assertContains('address.view-own', $groupedNames);
        $this->assertNotContains('rbac.manage', $groupedNames);

        $searched = $this->getJson('/api/v1/admin/permissions?search=rbac&per_page=100')->assertOk();
        $this->assertSame(
            ['rbac.manage', 'rbac.view'],
            collect($searched->json('data'))->pluck('name')->sort()->values()->all()
        );
    }

    // ── Permission grants on roles (`rbac.manage`) ───────────────────────────

    public function test_admin_can_grant_permissions_to_a_role(): void
    {
        $this->actingAsAdmin();

        $courier = User::factory()->create(['phone' => '09120000001']);
        $courier->assignRole('customer');
        $courier->assignRole('delivery');
        $this->assertFalse($courier->can('address.view-any'));

        $this->postJson('/api/v1/admin/roles/delivery/permissions', [
            'permissions' => ['address.view-any'],
        ])->assertOk()->assertJsonPath('message', 'Permissions granted successfully.');

        // Cache proof: the capability is effective on the next check, no reseed.
        $this->assertTrue($courier->fresh()->can('address.view-any'));
        $this->assertTrue(Role::findByName('delivery', 'web')->hasPermissionTo('address.view-any'));
    }

    public function test_granting_a_permission_twice_changes_nothing(): void
    {
        $this->actingAsAdmin();

        $role = Role::findByName('delivery', 'web');
        $permissionId = Permission::findByName('address.view-any', 'web')->id;

        $this->postJson('/api/v1/admin/roles/delivery/permissions', [
            'permissions' => ['address.view-any'],
        ])->assertOk();
        $this->postJson('/api/v1/admin/roles/delivery/permissions', [
            'permissions' => ['address.view-any'],
        ])->assertOk();

        $this->assertSame(1, DB::table(config('permission.table_names.role_has_permissions'))
            ->where('role_id', $role->id)
            ->where(config('permission.column_names.permission_pivot_key') ?? 'permission_id', $permissionId)
            ->count());
    }

    public function test_admin_can_revoke_permissions_from_a_role(): void
    {
        $this->actingAsAdmin();

        $role = Role::findByName('delivery', 'web');
        $role->givePermissionTo('address.view-any');

        $this->deleteJson('/api/v1/admin/roles/delivery/permissions', [
            'permissions' => ['address.view-any'],
        ])->assertOk()->assertJsonPath('message', 'Permissions revoked successfully.');

        $this->assertFalse($role->fresh()->hasPermissionTo('address.view-any'));
    }

    public function test_revoking_an_unheld_permission_is_a_no_op(): void
    {
        $this->actingAsAdmin();

        $this->deleteJson('/api/v1/admin/roles/delivery/permissions', [
            'permissions' => ['address.view-any'],
        ])->assertOk();

        $this->assertFalse(Role::findByName('delivery', 'web')->hasPermissionTo('address.view-any'));
    }

    public function test_admin_role_permissions_are_protected(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/roles/admin/permissions', [
            'permissions' => ['address.view-any'],
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        $this->deleteJson('/api/v1/admin/roles/admin/permissions', [
            'permissions' => ['rbac.manage'],
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('rbac.manage'));
    }

    public function test_unknown_permission_names_are_rejected(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/roles/delivery/permissions', [
            'permissions' => ['ghost.permission'],
        ])->assertStatus(422)->assertJsonValidationErrors('permissions.0');
    }

    public function test_permission_payload_is_validated(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/roles/delivery/permissions', [])
            ->assertStatus(422)->assertJsonValidationErrors('permissions');

        $this->postJson('/api/v1/admin/roles/delivery/permissions', ['permissions' => []])
            ->assertStatus(422)->assertJsonValidationErrors('permissions');

        $this->postJson('/api/v1/admin/roles/delivery/permissions', ['permissions' => 'address.view-any'])
            ->assertStatus(422)->assertJsonValidationErrors('permissions');

        $this->postJson('/api/v1/admin/roles/delivery/permissions', [
            'permissions' => ['address.view-any', 'address.view-any'],
        ])->assertStatus(422)->assertJsonValidationErrors('permissions.1');
    }

    // ── Role grants on users (`rbac.manage`) ─────────────────────────────────

    public function test_admin_can_assign_a_role_to_a_user(): void
    {
        $this->actingAsAdmin();

        $user = User::factory()->create();
        $user->assignRole('customer');

        $response = $this->postJson("/api/v1/admin/users/{$user->id}/roles", [
            'role' => 'delivery',
        ])->assertOk();

        $this->assertSame('Role assigned successfully.', $response->json('message'));

        $user->refresh();
        $this->assertTrue($user->hasRole('customer'));
        $this->assertTrue($user->hasRole('delivery'));
        $this->assertEqualsCanonicalizing(['customer', 'delivery'], $response->json('data.roles'));
    }

    public function test_assigning_a_role_twice_changes_nothing(): void
    {
        $this->actingAsAdmin();

        $user = User::factory()->create();
        $user->assignRole('customer');

        $this->postJson("/api/v1/admin/users/{$user->id}/roles", ['role' => 'delivery'])->assertOk();
        $this->postJson("/api/v1/admin/users/{$user->id}/roles", ['role' => 'delivery'])->assertOk();

        $this->assertSame(1, $user->fresh()->roles()->where('name', 'delivery')->count());
    }

    public function test_admin_can_revoke_a_role_from_a_user(): void
    {
        $this->actingAsAdmin();

        $user = User::factory()->create();
        $user->assignRole('customer');
        $user->assignRole('delivery');

        $this->deleteJson("/api/v1/admin/users/{$user->id}/roles", [
            'role' => 'delivery',
        ])->assertOk()->assertJsonPath('message', 'Role revoked successfully.');

        $user->refresh();
        $this->assertTrue($user->hasRole('customer'));
        $this->assertFalse($user->hasRole('delivery'));
    }

    public function test_revoking_a_role_the_user_does_not_hold_is_a_no_op(): void
    {
        $this->actingAsAdmin();

        $user = User::factory()->create();
        $user->assignRole('customer');

        $this->deleteJson("/api/v1/admin/users/{$user->id}/roles", ['role' => 'delivery'])->assertOk();

        $user->refresh();
        $this->assertTrue($user->hasRole('customer'));
        $this->assertFalse($user->hasRole('delivery'));
    }

    public function test_the_admin_role_cannot_be_assigned_or_revoked(): void
    {
        $this->actingAsAdmin();

        $user = User::factory()->create();
        $user->assignRole('customer');

        $this->postJson("/api/v1/admin/users/{$user->id}/roles", ['role' => 'admin'])
            ->assertStatus(422)->assertJsonValidationErrors('role');

        $this->deleteJson("/api/v1/admin/users/{$user->id}/roles", ['role' => 'admin'])
            ->assertStatus(422)->assertJsonValidationErrors('role');

        $user->refresh();
        $this->assertFalse($user->hasRole('admin'));
    }

    public function test_unknown_role_cannot_be_assigned(): void
    {
        $this->actingAsAdmin();

        $user = User::factory()->create();

        $this->postJson("/api/v1/admin/users/{$user->id}/roles", ['role' => 'ghost'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_unknown_user_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/users/999999/roles', ['role' => 'delivery'])->assertNotFound();
        $this->deleteJson('/api/v1/admin/users/999999/roles', ['role' => 'delivery'])->assertNotFound();
    }

    // ── Authorization matrix ─────────────────────────────────────────────────

    public function test_rbac_reads_are_permission_gated(): void
    {
        $this->getJson('/api/v1/admin/roles')->assertStatus(401);
        $this->getJson('/api/v1/admin/roles/customer')->assertStatus(401);
        $this->getJson('/api/v1/admin/permissions')->assertStatus(401);

        $this->actingAsCustomer();

        $this->getJson('/api/v1/admin/roles')->assertForbidden();
        $this->getJson('/api/v1/admin/roles/customer')->assertForbidden();
        $this->getJson('/api/v1/admin/permissions')->assertForbidden();
    }

    public function test_rbac_writes_are_permission_gated(): void
    {
        $target = User::factory()->create();

        $this->postJson('/api/v1/admin/roles/delivery/permissions', ['permissions' => ['address.view-any']])->assertStatus(401);
        $this->deleteJson('/api/v1/admin/roles/delivery/permissions', ['permissions' => ['address.view-any']])->assertStatus(401);
        $this->postJson("/api/v1/admin/users/{$target->id}/roles", ['role' => 'delivery'])->assertStatus(401);
        $this->deleteJson("/api/v1/admin/users/{$target->id}/roles", ['role' => 'delivery'])->assertStatus(401);

        $this->actingAsCustomer();

        $this->postJson('/api/v1/admin/roles/delivery/permissions', ['permissions' => ['address.view-any']])->assertForbidden();
        $this->deleteJson('/api/v1/admin/roles/delivery/permissions', ['permissions' => ['address.view-any']])->assertForbidden();
        $this->postJson("/api/v1/admin/users/{$target->id}/roles", ['role' => 'delivery'])->assertForbidden();
        $this->deleteJson("/api/v1/admin/users/{$target->id}/roles", ['role' => 'delivery'])->assertForbidden();
    }

    public function test_rbac_management_is_permission_based_not_role_based(): void
    {
        // No admin role — only the capability, exactly like every other module.
        $operator = User::factory()->create();
        $operator->givePermissionTo('rbac.manage');
        Sanctum::actingAs($operator);

        $this->postJson('/api/v1/admin/roles/delivery/permissions', [
            'permissions' => ['profile.view-any'],
        ])->assertOk();

        $this->assertTrue(Role::findByName('delivery', 'web')->hasPermissionTo('profile.view-any'));
    }

    public function test_rbac_view_alone_cannot_write(): void
    {
        $reader = User::factory()->create();
        $reader->givePermissionTo('rbac.view');
        Sanctum::actingAs($reader);

        $this->getJson('/api/v1/admin/roles')->assertOk();
        $this->getJson('/api/v1/admin/permissions')->assertOk();

        $this->postJson('/api/v1/admin/roles/delivery/permissions', [
            'permissions' => ['address.view-any'],
        ])->assertForbidden();
    }
}
