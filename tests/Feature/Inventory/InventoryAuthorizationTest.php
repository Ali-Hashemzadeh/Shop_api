<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Domain\Models\InventoryLedgerEntry;
use Modules\Inventory\Domain\Models\InventoryStock;
use Tests\TestCase;

/**
 * Verifies the authorization boundary between public and admin inventory routes.
 * No actingAsAdmin() in setUp — each test controls auth state explicitly.
 */
class InventoryAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedInventoryPermissions();
    }

    // ── Unauthenticated → 401 on admin routes ────────────────────────────────

    /** @test */
    public function unauthenticated_request_cannot_adjust_stock(): void
    {
        $this->postJson('/api/v1/inventory/adjust', [
            'sku' => 'TEST-SKU',
            'quantity_change' => 10,
            'type' => 'restock',
        ])->assertUnauthorized();
    }

    /** @test */
    public function unauthenticated_request_cannot_view_ledger(): void
    {
        $this->getJson('/api/v1/inventory/sku/TEST-SKU/ledger')
            ->assertUnauthorized();
    }

    // ── Customer (no permissions) → 403 on admin routes ──────────────────────

    /** @test */
    public function customer_cannot_adjust_stock(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/inventory/adjust', [
            'sku' => 'TEST-SKU',
            'quantity_change' => 10,
            'type' => 'restock',
        ])->assertForbidden();
    }

    /** @test */
    public function customer_cannot_view_ledger(): void
    {
        $this->actingAsCustomer();

        $this->getJson('/api/v1/inventory/sku/TEST-SKU/ledger')
            ->assertForbidden();
    }

    // ── Public routes → accessible without auth (200/404, never 401/403) ─────

    /** @test */
    public function public_sku_lookup_does_not_require_authentication(): void
    {
        InventoryStock::create(['sku' => 'PUBLIC-SKU', 'quantity' => 5, 'reserved_quantity' => 0]);

        $this->getJson('/api/v1/inventory/sku/PUBLIC-SKU')
            ->assertOk();
    }

    /** @test */
    public function public_sku_lookup_returns_404_not_401_for_unknown_sku(): void
    {
        $this->getJson('/api/v1/inventory/sku/GHOST-SKU')
            ->assertNotFound();
    }

    /** @test */
    public function public_batch_lookup_does_not_require_authentication(): void
    {
        $this->postJson('/api/v1/inventory/batch', ['skus' => ['GHOST-SKU']])
            ->assertOk();
    }

    // ── Permission-based (not role-based) proof ──────────────────────────────

    /** @test */
    public function user_with_inventory_manage_permission_can_adjust_without_admin_role(): void
    {
        $user = $this->actingAsCustomer();
        $user->givePermissionTo('inventory.stock.manage');

        $this->postJson('/api/v1/inventory/adjust', [
            'sku' => 'PERM-TEST',
            'quantity_change' => 10,
            'type' => 'restock',
        ])->assertOk();
    }

    /** @test */
    public function user_with_ledger_view_permission_can_view_ledger_without_admin_role(): void
    {
        $user = $this->actingAsCustomer();
        $user->givePermissionTo('inventory.ledger.view');

        InventoryLedgerEntry::create([
            'sku' => 'PERM-TEST',
            'type' => 'restock',
            'quantity_change' => 10,
        ]);

        $this->getJson('/api/v1/inventory/sku/PERM-TEST/ledger')
            ->assertOk();
    }

    /** @test */
    public function user_with_only_ledger_view_cannot_adjust_stock(): void
    {
        $user = $this->actingAsCustomer();
        $user->givePermissionTo('inventory.ledger.view');

        $this->postJson('/api/v1/inventory/adjust', [
            'sku' => 'PERM-TEST',
            'quantity_change' => 10,
            'type' => 'restock',
        ])->assertForbidden();
    }
}
