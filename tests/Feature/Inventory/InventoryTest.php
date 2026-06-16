<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Application\Actions\CommitReservationAction;
use Modules\Inventory\Application\Actions\ReleaseReservationAction;
use Modules\Inventory\Application\Actions\ReserveStockAction;
use Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use Modules\Inventory\Domain\Models\InventoryLedgerEntry;
use Modules\Inventory\Domain\Models\InventoryStock;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedInventoryPermissions();
    }

    // ── Public: GET /api/v1/inventory/sku/{sku} ──────────────────────────────

    /** @test */
    public function it_returns_stock_dto_for_known_sku(): void
    {
        InventoryStock::create(['sku' => 'SHIRT-M', 'quantity' => 10, 'reserved_quantity' => 3]);

        $this->getJson('/api/v1/inventory/sku/SHIRT-M')
            ->assertOk()
            ->assertJson([
                'sku' => 'SHIRT-M',
                'available_quantity' => 7,
                'physical_quantity' => 10,
                'reserved_quantity' => 3,
            ]);
    }

    /** @test */
    public function it_returns_404_for_unknown_sku(): void
    {
        $this->getJson('/api/v1/inventory/sku/NONEXISTENT-SKU')
            ->assertNotFound();
    }

    // ── Public: POST /api/v1/inventory/batch ─────────────────────────────────

    /** @test */
    public function it_returns_batch_stock_keyed_by_sku(): void
    {
        InventoryStock::create(['sku' => 'SKU-A', 'quantity' => 5, 'reserved_quantity' => 0]);
        InventoryStock::create(['sku' => 'SKU-B', 'quantity' => 20, 'reserved_quantity' => 5]);

        $this->postJson('/api/v1/inventory/batch', ['skus' => ['SKU-A', 'SKU-B']])
            ->assertOk()
            ->assertJsonPath('SKU-A.available_quantity', 5)
            ->assertJsonPath('SKU-B.available_quantity', 15);
    }

    /** @test */
    public function it_silently_omits_unknown_skus_from_batch(): void
    {
        InventoryStock::create(['sku' => 'SKU-A', 'quantity' => 5, 'reserved_quantity' => 0]);

        $data = $this->postJson('/api/v1/inventory/batch', ['skus' => ['SKU-A', 'GHOST-SKU']])
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('SKU-A', $data);
        $this->assertArrayNotHasKey('GHOST-SKU', $data);
    }

    // ── Admin: POST /api/v1/inventory/adjust ─────────────────────────────────

    /** @test */
    public function admin_can_restock_a_sku(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/inventory/adjust', [
            'sku' => 'PANTS-L',
            'quantity_change' => 50,
            'type' => 'restock',
            'notes' => 'Initial stock arrival',
        ])
            ->assertOk()
            ->assertJson([
                'sku' => 'PANTS-L',
                'available_quantity' => 50,
                'physical_quantity' => 50,
                'reserved_quantity' => 0,
            ]);
    }

    /** @test */
    public function adjusting_stock_creates_a_ledger_entry(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/inventory/adjust', [
            'sku' => 'PANTS-L',
            'quantity_change' => 50,
            'type' => 'restock',
            'notes' => 'Warehouse restock',
        ])->assertOk();

        $this->assertDatabaseHas('inventory_ledger_entries', [
            'sku' => 'PANTS-L',
            'type' => 'restock',
            'quantity_change' => 50,
            'notes' => 'Warehouse restock',
        ]);
    }

    /** @test */
    public function adjust_returns_422_when_quantity_change_is_zero(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/inventory/adjust', [
            'sku' => 'PANTS-L',
            'quantity_change' => 0,
            'type' => 'restock',
        ])->assertUnprocessable();
    }

    // ── Admin: GET /api/v1/inventory/sku/{sku}/ledger ────────────────────────

    /** @test */
    public function admin_can_view_paginated_ledger_for_a_sku(): void
    {
        $this->actingAsAdmin();

        InventoryLedgerEntry::create([
            'sku' => 'COAT-XL',
            'type' => 'restock',
            'quantity_change' => 100,
        ]);

        $this->getJson('/api/v1/inventory/sku/COAT-XL/ledger')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonPath('data.0.sku', 'COAT-XL')
            ->assertJsonPath('data.0.type', 'restock');
    }

    /** @test */
    public function ledger_returns_empty_paginated_result_for_sku_with_no_entries(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/inventory/sku/NO-ENTRIES-SKU/ledger')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    // ── Business logic: ReserveStockAction ───────────────────────────────────

    /** @test */
    public function reserve_stock_succeeds_when_sufficient_stock_is_available(): void
    {
        InventoryStock::create(['sku' => 'BOOT-42', 'quantity' => 10, 'reserved_quantity' => 0]);

        $result = app(ReserveStockAction::class)->handle('BOOT-42', 3, 99);

        $this->assertTrue($result);
        $this->assertDatabaseHas('inventory_stocks', [
            'sku' => 'BOOT-42',
            'quantity' => 10,
            'reserved_quantity' => 3,
        ]);
        $this->assertDatabaseHas('inventory_ledger_entries', [
            'sku' => 'BOOT-42',
            'type' => 'allocation',
            'quantity_change' => -3,
            'reference_id' => 99,
        ]);
    }

    /** @test */
    public function reserve_stock_throws_when_requested_exceeds_available(): void
    {
        InventoryStock::create(['sku' => 'BOOT-42', 'quantity' => 2, 'reserved_quantity' => 0]);

        $this->expectException(InsufficientStockException::class);

        app(ReserveStockAction::class)->handle('BOOT-42', 5, 99);
    }

    /** @test */
    public function reservation_cannot_exceed_already_limited_available_pool(): void
    {
        // 10 physical, 8 reserved → 2 available; trying to reserve 3 must fail
        InventoryStock::create(['sku' => 'BOOT-42', 'quantity' => 10, 'reserved_quantity' => 8]);

        $this->expectException(InsufficientStockException::class);

        app(ReserveStockAction::class)->handle('BOOT-42', 3, 99);
    }

    // ── Business logic: CommitReservationAction ───────────────────────────────

    /** @test */
    public function commit_reservation_deducts_physical_and_reserved_quantities(): void
    {
        InventoryStock::create(['sku' => 'BOOT-42', 'quantity' => 10, 'reserved_quantity' => 5]);

        app(CommitReservationAction::class)->handle('BOOT-42', 5, 99);

        $this->assertDatabaseHas('inventory_stocks', [
            'sku' => 'BOOT-42',
            'quantity' => 5,
            'reserved_quantity' => 0,
        ]);
        $this->assertDatabaseHas('inventory_ledger_entries', [
            'sku' => 'BOOT-42',
            'type' => 'sale',
            'quantity_change' => -5,
        ]);
    }

    // ── Business logic: ReleaseReservationAction ──────────────────────────────

    /** @test */
    public function release_reservation_returns_units_to_the_available_pool(): void
    {
        InventoryStock::create(['sku' => 'BOOT-42', 'quantity' => 10, 'reserved_quantity' => 5]);

        app(ReleaseReservationAction::class)->handle('BOOT-42', 5, 99);

        $this->assertDatabaseHas('inventory_stocks', [
            'sku' => 'BOOT-42',
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);
        $this->assertDatabaseHas('inventory_ledger_entries', [
            'sku' => 'BOOT-42',
            'type' => 'release',
            'quantity_change' => 5,
        ]);
    }
}
