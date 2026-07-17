<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Cart\Domain\Models\Cart;
use Modules\Cart\Domain\Models\CartItem;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Identity\Domain\Models\User;
use Modules\Inventory\Domain\Models\InventoryStock;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Shipment\Domain\Models\DeliverySlot;
use Modules\Shipment\Domain\Models\DeliveryWorkingPeriod;
use Tests\TestCase;

abstract class ShipmentTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedInventoryPermissions();
        $this->seedOrderPermissions();
        $this->seedPaymentPermissions();
        $this->seedShipmentPermissions();
    }

    protected function createAddress(int $userId, ?int $provinceId = null, ?int $cityId = null): int
    {
        return DB::table('addresses')->insertGetId([
            'user_id' => $userId,
            'title' => 'Home',
            'province_id' => $provinceId,
            'city_id' => $cityId,
            'postal_code' => '1234512345',
            'address' => '123 Test Street',
            'is_default_shipping' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function createVariantWithStock(string $sku, int $basePrice = 50000, int $qty = 10): void
    {
        $product = Product::create([
            'title' => "Product {$sku}",
            'slug' => strtolower($sku),
            'status' => 'published',
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'type' => 'color',
            'base_price' => $basePrice,
            'is_default' => true,
            'attributes' => [],
        ]);

        InventoryStock::create(['sku' => $sku, 'quantity' => $qty, 'reserved_quantity' => 0]);
    }

    protected function addToCart(int $userId, string $sku, int $qty = 1): Cart
    {
        $cart = Cart::firstOrCreate(['user_id' => $userId]);
        CartItem::updateOrCreate(['cart_id' => $cart->id, 'sku' => $sku], ['quantity' => $qty]);

        return $cart;
    }

    protected function createSlot(
        string $date,
        string $start = '15:00:00',
        string $end = '16:30:00',
        int $capacity = 3,
        int $adminReserved = 0,
        string $status = 'open',
    ): DeliverySlot {
        return DeliverySlot::create([
            'delivery_date' => $date,
            'starts_at' => $start,
            'ends_at' => $end,
            'capacity' => $capacity,
            'admin_reserved_capacity' => $adminReserved,
            'status' => $status,
        ]);
    }

    /** A slot comfortably inside the booking window and lead time (2 days out at 15:00). */
    protected function createBookableSlot(int $capacity = 3, int $adminReserved = 0, string $start = '15:00:00', string $end = '16:30:00', string $status = 'open'): DeliverySlot
    {
        return $this->createSlot(
            Carbon::today()->addDays(2)->toDateString(),
            $start,
            $end,
            $capacity,
            $adminReserved,
            $status,
        );
    }

    /**
     * Insert a province + city and return their ids.
     *
     * @return array{0: int, 1: int}
     */
    protected function createProvinceCity(): array
    {
        $provinceId = DB::table('provinces')->insertGetId([
            'name' => 'Tehran', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cityId = DB::table('cities')->insertGetId([
            'province_id' => $provinceId, 'name' => 'Tehran', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$provinceId, $cityId];
    }

    protected function createWorkingPeriod(int $weekday, string $start, string $end): DeliveryWorkingPeriod
    {
        return DeliveryWorkingPeriod::create([
            'weekday' => $weekday,
            'starts_at' => $start,
            'ends_at' => $end,
            'is_active' => true,
        ]);
    }

    protected function markOrderPaid(int $orderId, string $ref = 'TEST-REF'): void
    {
        app(OrderManagerInterface::class)->markAsPaid($orderId, $ref.'-'.$orderId);
    }

    protected function actingAsOperator(): User
    {
        // Admin role receives all shipment permissions via the seeder.
        return $this->actingAsAdmin();
    }
}
