<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment\Post1405;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Identity\Domain\Models\User;
use Modules\Order\Domain\Models\Order;
use Modules\Shipment\Domain\Contracts\ShippingCostCalculatorInterface;
use Modules\Shipment\Domain\DTOs\ShipmentParcelDTO;
use Modules\Shipment\Domain\Models\PostalService1405;
use Modules\Shipment\Domain\Models\PostalTariff1405;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Shipment\Domain\Services\MissingWeightFallbackPolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Shipment\ShipmentTestCase;

/**
 * The fixed-price Normal Post (پست عادی, `post_standard`) fallback: a cart in which
 * ANY item has a missing / zero / negative shipping weight gets exactly one postal
 * option at the configured flat price, and the 1405 methods are withdrawn for it. A
 * cart whose weights are all valid keeps the 1405 engine and can never buy the
 * fallback. GET /shipment/methods and POST /orders always agree.
 */
class MissingWeightNormalPostFallbackTest extends ShipmentTestCase
{
    private const PRICE = 130000;

    /** Every 1405 parcel method enabled by default. */
    private const DYNAMIC_POSTAL = [
        'post_registered_parcel',
        'post_urban_3h_parcel', 'post_urban_sameday_parcel', 'post_urban_nextday_parcel',
        'post_intercity_sameday_parcel', 'post_intercity_d1_parcel', 'post_intercity_d2_parcel', 'post_intercity_d3_parcel',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPost1405();
        // Pinned so the suite never depends on a developer's .env value.
        config(['shipping.missing_weight_fallback' => ['enabled' => true, 'price_toman' => self::PRICE]]);
    }

    private function methods(int $addressId): Collection
    {
        return collect($this->getJson("/api/v1/shipment/methods?address_id={$addressId}")->assertOk()->json('data'))->keyBy('code');
    }

    /**
     * A customer whose cart holds the given lines, plus a postal address.
     *
     * @param  array<string, array{0: ?int, 1: int}>  $lines  sku => [unit grams, qty]
     * @return array{0: User, 1: int}
     */
    private function customerWithCart(array $lines, int $cityId = self::AHVAZ, int $price = 50000): array
    {
        $user = $this->actingAsCustomer();

        foreach ($lines as $sku => [$grams, $qty]) {
            $this->createWeightedVariant($sku, $grams, $price, 50);
            $this->addToCart($user->id, $sku, $qty);
        }

        return [$user, $this->postalAddress($user->id, $cityId)];
    }

    private function assertNormalPostOnly(Collection $methods): void
    {
        $postal = $methods->where('type', 'postal');
        $this->assertSame(['post_standard'], $postal->where('available', true)->keys()->values()->all());

        $fallback = $methods['post_standard'];
        $this->assertSame('پست عادی', $fallback['title']);
        $this->assertSame(self::PRICE, $fallback['price']);
        $this->assertSame('fixed_missing_weight_fallback', $fallback['pricing_mode']);
        $this->assertSame(['fixed_shipping_price' => self::PRICE, 'total' => self::PRICE], $fallback['price_breakdown']);

        foreach (self::DYNAMIC_POSTAL as $code) {
            $this->assertFalse($methods[$code]['available'], $code);
            $this->assertNull($methods[$code]['price'], $code);
            $this->assertSame('weight_missing', $methods[$code]['unavailable_code'], $code);
        }

        $this->assertArrayNotHasKey('post_express', $methods->all());
    }

    private function checkout(int $addressId, string $method = 'post_standard'): TestResponse
    {
        return $this->postJson('/api/v1/orders', ['shipment_method_code' => $method, 'address_id' => $addressId]);
    }

    // ── 1–7: method list by cart shape ─────────────────────────────────────────

    #[Test]
    public function a_cart_with_valid_weights_gets_the_1405_methods_and_no_normal_post(): void
    {
        [, $addressId] = $this->customerWithCart(['OK-1' => [910, 1]]);

        $methods = $this->methods($addressId);

        $this->assertArrayNotHasKey('post_standard', $methods->all());
        // The 1405 price is unchanged by this feature (spec example: 176,500).
        $this->assertTrue($methods['post_intercity_d3_parcel']['available']);
        $this->assertSame(176500, $methods['post_intercity_d3_parcel']['price']);
        $this->assertSame('post_1405_tariff', $methods['post_intercity_d3_parcel']['pricing_mode']);
    }

    #[Test]
    public function a_null_weight_gets_normal_post_only(): void
    {
        [, $addressId] = $this->customerWithCart(['NULL-W' => [null, 1]]);

        $methods = $this->methods($addressId);
        $this->assertNormalPostOnly($methods);

        // The exact public shape (documented in POST_1405_SHIPPING.md §6.6).
        $this->assertSame([
            'code' => 'post_standard',
            'title' => 'پست عادی',
            'type' => 'postal',
            'price' => self::PRICE,
            'requires_address' => true,
            'requires_delivery_slot' => false,
            'supports_tracking' => true,
            'estimated_min_days' => 3,
            'estimated_max_days' => 7,
            'available' => true,
            'unavailable_reason' => null,
            'unavailable_code' => null,
            'currency' => 'TOMAN',
            'pricing_mode' => 'fixed_missing_weight_fallback',
            'tariff_year' => null,
            'scope' => null,
            'package_kind' => null,
            'weight_grams' => null,
            'rate_zone' => null,
            'price_breakdown' => ['fixed_shipping_price' => self::PRICE, 'total' => self::PRICE],
        ], $methods['post_standard']);
        // Listed first, ahead of the withdrawn 1405 methods.
        $this->assertSame('post_standard', $methods->keys()->first());
    }

    #[Test]
    public function a_zero_weight_gets_normal_post_only(): void
    {
        [, $addressId] = $this->customerWithCart(['ZERO-W' => [0, 1]]);

        $this->assertNormalPostOnly($this->methods($addressId));
    }

    #[Test]
    public function a_negative_weight_gets_normal_post_only(): void
    {
        [, $addressId] = $this->customerWithCart(['NEG-W' => [-250, 1]]);

        $this->assertNormalPostOnly($this->methods($addressId));
        // And the parcel itself classifies it as missing (never as a light parcel).
        $parcel = ShipmentParcelDTO::fromLines([['sku' => 'NEG-W', 'quantity' => 1, 'weight_grams' => -250]]);
        $this->assertSame(['NEG-W'], $parcel->skusMissingWeight());
        $this->assertTrue(app(MissingWeightFallbackPolicy::class)->appliesTo($parcel));
    }

    #[Test]
    public function three_items_with_one_missing_weight_get_normal_post_only(): void
    {
        [, $addressId] = $this->customerWithCart(['A-300' => [300, 1], 'B-NONE' => [null, 1], 'C-400' => [400, 1]]);

        $methods = $this->methods($addressId);

        $this->assertNormalPostOnly($methods);
        // Never priced from the items that do have a weight.
        $this->assertNull($methods['post_standard']['weight_grams']);
        $this->assertNull($methods['post_standard']['tariff_year']);
        $this->assertNull($methods['post_standard']['rate_zone']);
        $this->assertSame(['B-NONE'], $methods['post_intercity_d3_parcel']['missing_weight_skus']);
    }

    #[Test]
    public function three_items_all_missing_weight_get_normal_post_only(): void
    {
        [, $addressId] = $this->customerWithCart(['X-1' => [null, 1], 'X-2' => [0, 2], 'X-3' => [null, 3]]);

        $this->assertNormalPostOnly($this->methods($addressId));
    }

    #[Test]
    public function quantities_never_multiply_the_flat_price(): void
    {
        [, $addressId] = $this->customerWithCart(['QTY-W' => [300, 4], 'QTY-NONE' => [null, 3]], self::AHVAZ, 20000);

        $this->assertNormalPostOnly($this->methods($addressId));

        $this->checkout($addressId)
            ->assertCreated()
            ->assertJsonPath('shipping_cost', self::PRICE)
            ->assertJsonPath('total_amount', 7 * 20000 + self::PRICE);
    }

    // ── 8–12: checkout ─────────────────────────────────────────────────────────

    #[Test]
    public function a_dynamic_method_is_rejected_when_an_item_has_no_weight(): void
    {
        [, $addressId] = $this->customerWithCart(['DYN-W' => [500, 1], 'DYN-NONE' => [null, 1]]);

        foreach (['post_intercity_d3_parcel', 'post_registered_parcel'] as $code) {
            $this->checkout($addressId, $code)
                ->assertStatus(422)
                ->assertJsonValidationErrors('shipment_method_code')
                ->assertJsonMissingValidationErrors('items.DYN-NONE.weight_grams');
        }

        $this->assertStringContainsString('refresh', $this->checkout($addressId, 'post_intercity_d3_parcel')->json('errors.shipment_method_code.0'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('inventory_stocks', ['sku' => 'DYN-W', 'reserved_quantity' => 0]);
    }

    #[Test]
    public function normal_post_is_rejected_when_every_weight_is_valid(): void
    {
        [, $addressId] = $this->customerWithCart(['VALID-1' => [500, 1], 'VALID-2' => [200, 2]]);

        $this->checkout($addressId)
            ->assertStatus(422)
            ->assertJsonValidationErrors('shipment_method_code');
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function normal_post_checkout_charges_exactly_the_configured_flat_price(): void
    {
        [, $addressId] = $this->customerWithCart(['FLAT-NONE' => [null, 1]], self::AHVAZ, 90000);
        $listed = $this->methods($addressId)['post_standard'];

        $orderId = $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'post_standard',
            'address_id' => $addressId,
            // Never trusted.
            'shipping_cost' => 1,
            'price_breakdown' => ['total' => 1],
        ])
            ->assertCreated()
            ->assertJsonPath('shipment_method_code', 'post_standard')
            ->assertJsonPath('shipping_cost', self::PRICE)
            ->assertJsonPath('tax_amount', 0)
            ->assertJsonPath('total_amount', 90000 + self::PRICE)
            ->json('id');

        $order = Order::findOrFail($orderId);
        $this->assertSame($listed['price'], $order->shipping_cost);
        $snapshot = $order->shipment_snapshot;
        $this->assertSame('post_standard', $snapshot['method_code']);
        $this->assertSame('پست عادی', $snapshot['method_title']);
        $this->assertSame('postal', $snapshot['method_type']);
        $this->assertSame(self::PRICE, $snapshot['shipping_cost']);
        $this->assertSame('fixed_missing_weight_fallback', $snapshot['pricing_mode']);
        $this->assertSame(['fixed_shipping_price' => self::PRICE, 'total' => self::PRICE], $snapshot['price_breakdown']);
        $this->assertNull($snapshot['tariff_year']);
        $this->assertNull($snapshot['weight_grams']);
        $this->assertNull($snapshot['rate_zone']);
    }

    #[Test]
    public function the_fallback_never_runs_the_1405_weight_calculation(): void
    {
        [, $addressId] = $this->customerWithCart(['NOCALC-W' => [500, 1], 'NOCALC-NONE' => [null, 1]]);
        $this->mock(ShippingCostCalculatorInterface::class)->shouldNotReceive('calculate');

        $this->assertSame(self::PRICE, $this->methods($addressId)['post_standard']['price']);
        $this->checkout($addressId)->assertCreated()->assertJsonPath('shipping_cost', self::PRICE);
    }

    #[Test]
    public function no_surcharge_tax_or_packaging_is_added_on_top_of_the_flat_price(): void
    {
        // Tehran (+20 %), Mashhad (+15 % metro, +5 % large province), Ahvaz (+15 %),
        // a test-only non-metro city: one price everywhere. Each checkout replaces the
        // previous pending order, so one cart serves every destination.
        [$user] = $this->customerWithCart(['GEO-NONE' => [null, 2]], self::AHVAZ, 10000);

        foreach ([self::TEHRAN, self::MASHHAD, self::AHVAZ, self::DEZFUL] as $cityId) {
            $addressId = $this->postalAddress($user->id, $cityId);

            $fallback = $this->methods($addressId)['post_standard'];
            $this->assertSame(self::PRICE, $fallback['price'], (string) $cityId);
            $this->assertSame(['fixed_shipping_price', 'total'], array_keys($fallback['price_breakdown']));

            $this->checkout($addressId)
                ->assertCreated()
                ->assertJsonPath('shipping_cost', self::PRICE)
                ->assertJsonPath('tax_amount', 0)
                ->assertJsonPath('total_amount', 2 * 10000 + self::PRICE);
        }
    }

    // ── 13–18: other methods, addresses, cart, configuration ──────────────────

    #[Test]
    public function local_delivery_and_pickup_still_work_with_a_missing_weight(): void
    {
        $user = $this->actingAsCustomer();
        $this->createWeightedVariant('LOC-NONE', null, 30000);
        $this->addToCart($user->id, 'LOC-NONE');
        $localAddressId = $this->createAddress($user->id);

        $methods = $this->methods($localAddressId);
        $this->assertTrue($methods['local_delivery']['available']);
        $this->assertSame((int) config('shipment.methods.local_delivery.price'), $methods['local_delivery']['price']);
        $this->assertTrue($methods['in_person_pickup']['available']);

        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'in_person_pickup'])
            ->assertCreated()
            ->assertJsonPath('shipping_cost', 0)
            ->assertJsonPath('total_amount', 30000);

        $this->addToCart($user->id, 'LOC-NONE');
        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'local_delivery',
            'address_id' => $localAddressId,
            'delivery_slot_id' => $this->createBookableSlot()->id,
        ])
            ->assertCreated()
            ->assertJsonPath('shipping_cost', (int) config('shipment.methods.local_delivery.price'));
    }

    #[Test]
    public function normal_post_requires_an_address(): void
    {
        $user = $this->actingAsCustomer();
        $this->createWeightedVariant('ADDR-NONE', null);
        $this->addToCart($user->id, 'ADDR-NONE');

        // Not listed without an address — only address-free methods are.
        $codes = collect($this->getJson('/api/v1/shipment/methods')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame(['in_person_pickup'], $codes);

        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'post_standard'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('address_id');
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function an_invalid_foreign_or_incomplete_address_is_rejected(): void
    {
        [$user] = $this->customerWithCart(['BAD-ADDR' => [null, 1]]);
        $foreignAddressId = $this->postalAddress(User::factory()->create()->id);

        foreach ([999999, $foreignAddressId] as $addressId) {
            $this->getJson("/api/v1/shipment/methods?address_id={$addressId}")
                ->assertStatus(422)->assertJsonValidationErrors('address_id');
            $this->checkout($addressId)
                ->assertStatus(422)->assertJsonValidationErrors('address_id');
        }

        // An owned address without a city cannot be posted to, even at a flat price.
        $noCityId = $this->createAddress($user->id, 13, null);
        $this->assertSame('destination_incomplete', $this->methods($noCityId)['post_standard']['unavailable_code']);
        $this->checkout($noCityId)->assertStatus(422)->assertJsonValidationErrors('address_id');

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function normal_post_is_withdrawn_inside_the_local_delivery_area(): void
    {
        config(['shipment.local_delivery.city_ids' => [self::QOM]]);
        [, $addressId] = $this->customerWithCart(['QOM-NONE' => [null, 1]], self::QOM);

        $methods = $this->methods($addressId);
        $this->assertFalse($methods['post_standard']['available']);
        $this->assertNull($methods['post_standard']['price']);
        $this->assertSame('inside_local_delivery_area', $methods['post_standard']['unavailable_code']);
        $this->assertSame([], $methods->where('type', 'postal')->where('available', true)->all());
        $this->assertTrue($methods['local_delivery']['available']);

        $this->checkout($addressId)
            ->assertStatus(422)
            ->assertJsonValidationErrors('shipment_method_code');
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function an_empty_cart_is_rejected(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->postalAddress($user->id);

        $this->assertArrayNotHasKey('post_standard', $this->methods($addressId)->all());
        $this->checkout($addressId)->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function the_fallback_can_be_disabled(): void
    {
        config(['shipping.missing_weight_fallback.enabled' => false]);
        [, $addressId] = $this->customerWithCart(['OFF-W' => [500, 1], 'OFF-NONE' => [null, 1]]);

        $methods = $this->methods($addressId);
        $this->assertArrayNotHasKey('post_standard', $methods->all());
        $this->assertSame('weight_missing', $methods['post_intercity_d3_parcel']['unavailable_code']);

        $this->checkout($addressId)
            ->assertStatus(422)
            ->assertJsonValidationErrors('shipment_method_code');
        // The original per-item 422 for the 1405 methods.
        $this->checkout($addressId, 'post_intercity_d3_parcel')
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.OFF-NONE.weight_grams');
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function a_missing_or_non_positive_price_disables_the_fallback(): void
    {
        [, $addressId] = $this->customerWithCart(['NOPRICE' => [null, 1]]);

        foreach ([null, 0, -130000] as $price) {
            config(['shipping.missing_weight_fallback.price_toman' => $price]);

            $this->assertArrayNotHasKey('post_standard', $this->methods($addressId)->all());
            $this->checkout($addressId)->assertStatus(422)->assertJsonValidationErrors('shipment_method_code');
        }

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function the_env_price_is_read_as_strict_integer_tomans(): void
    {
        $cases = [
            '130000' => 130000,
            '13000' => 13000,
            '' => null,
            '0' => null,
            '-5' => null,
            '13,000' => null,      // never truncated to 13
            '130000.5' => null,    // never truncated to 130000
            'abc' => null,
        ];

        foreach ($cases as $raw => $expected) {
            $this->assertSame($expected, $this->loadShippingConfigWith(['SHIPMENT_POST_STANDARD_PRICE' => (string) $raw])['missing_weight_fallback']['price_toman'], "'{$raw}'");
        }

        $this->assertTrue($this->loadShippingConfigWith([])['missing_weight_fallback']['enabled']);
        $this->assertSame(130000, $this->loadShippingConfigWith([])['missing_weight_fallback']['price_toman']);
        $this->assertFalse($this->loadShippingConfigWith(['SHIPPING_MISSING_WEIGHT_FALLBACK_ENABLED' => 'false'])['missing_weight_fallback']['enabled']);
    }

    // ── 19–21: freshness, history, payment ────────────────────────────────────

    #[Test]
    public function checkout_uses_the_weights_re_read_from_catalog_not_the_listed_quote(): void
    {
        // Listed as a fallback cart, then the weight is filled in before checkout.
        [, $addressId] = $this->customerWithCart(['FRESH-1' => [null, 1]]);
        $this->assertNormalPostOnly($this->methods($addressId));

        ProductVariant::where('sku', 'FRESH-1')->update(['weight_grams' => 910]);

        $this->checkout($addressId)->assertStatus(422)->assertJsonValidationErrors('shipment_method_code');
        $this->checkout($addressId, 'post_intercity_d3_parcel')->assertCreated()->assertJsonPath('shipping_cost', 176500);

        // Listed as a priced 1405 cart, then the weight is removed before checkout.
        [, $otherAddressId] = $this->customerWithCart(['FRESH-2' => [910, 1]]);
        $this->assertTrue($this->methods($otherAddressId)['post_intercity_d3_parcel']['available']);

        ProductVariant::where('sku', 'FRESH-2')->update(['weight_grams' => null]);

        $this->checkout($otherAddressId, 'post_intercity_d3_parcel')->assertStatus(422)->assertJsonValidationErrors('shipment_method_code');
        $this->checkout($otherAddressId)->assertCreated()->assertJsonPath('shipping_cost', self::PRICE);
    }

    #[Test]
    public function historical_post_standard_orders_stay_readable_and_unchanged(): void
    {
        $user = $this->actingAsCustomer();
        $order = Order::createWithPublicCode([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => 135000,
            'shipping_cost' => 85000,
            'tax_amount' => 0,
            'shipment_method_code' => 'post_standard',
            'shipping_address' => [],
            'shipment_snapshot' => [
                'method_code' => 'post_standard', 'method_title' => 'Standard Post', 'method_type' => 'postal',
                'shipping_cost' => 85000, 'requires_address' => true, 'address' => null, 'delivery_slot' => null, 'pickup_location' => null,
            ],
            'customer_snapshot' => [],
        ]);

        $this->getJson("/api/v1/orders/{$order->public_code}")
            ->assertOk()
            ->assertJsonPath('shipping_cost', 85000)
            ->assertJsonPath('shipment_snapshot.method_title', 'Standard Post')
            ->assertJsonMissingPath('shipment_snapshot.pricing_mode');

        $this->markOrderPaid($order->id);

        $fresh = $order->fresh();
        $this->assertSame(85000, $fresh->shipping_cost);
        $this->assertSame(135000, $fresh->total_amount);
        $this->assertSame('Standard Post', $fresh->shipment_snapshot['method_title']);
        $shipment = Shipment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(85000, $shipment->shipping_cost);
        $this->assertSame('Standard Post', $shipment->method_title);
    }

    #[Test]
    public function payment_and_activation_never_recharge_the_saved_fallback_price(): void
    {
        [, $addressId] = $this->customerWithCart(['PAID-NONE' => [null, 2]], self::MASHHAD, 40000);

        $orderId = $this->checkout($addressId)->assertCreated()->json('id');

        // A later price change never reaches an existing order.
        config(['shipping.missing_weight_fallback.price_toman' => 999000]);

        $this->markOrderPaid($orderId);
        $this->markOrderPaid($orderId, 'DUPLICATE');

        $order = Order::findOrFail($orderId);
        $this->assertSame(self::PRICE, $order->shipping_cost);
        $this->assertSame(2 * 40000 + self::PRICE, $order->total_amount);

        $this->assertSame(1, Shipment::where('order_id', $orderId)->count());
        $shipment = Shipment::where('order_id', $orderId)->firstOrFail();
        $this->assertSame('post_standard', $shipment->method_code);
        $this->assertSame('پست عادی', $shipment->method_title);
        $this->assertSame(self::PRICE, $shipment->shipping_cost);
        $this->assertSame(['fixed_shipping_price' => self::PRICE, 'total' => self::PRICE], $shipment->price_breakdown);

        $this->getJson("/api/v1/shipments/{$shipment->public_code}")
            ->assertOk()
            ->assertJsonPath('price_breakdown.total', self::PRICE);
    }

    // ── Safety: the 1405 data is untouched ─────────────────────────────────────

    #[Test]
    public function the_fallback_touches_no_1405_service_or_tariff_row(): void
    {
        $services = PostalService1405::orderBy('code')->get()->toArray();
        $tariffs = PostalTariff1405::orderBy('id')->get()->toArray();
        $this->assertCount(21, $services);
        $this->assertCount(66, $tariffs);

        [, $addressId] = $this->customerWithCart(['SAFE-NONE' => [null, 1]]);
        $this->methods($addressId);
        $orderId = $this->checkout($addressId)->assertCreated()->json('id');
        $this->markOrderPaid($orderId);

        $this->assertSame($services, PostalService1405::orderBy('code')->get()->toArray());
        $this->assertSame($tariffs, PostalTariff1405::orderBy('id')->get()->toArray());
        $this->assertSame(0, DB::table('postal_services_1405')->where('code', 'post_standard')->count());
    }

    /**
     * Evaluate config/shipping.php against the given env values, restoring them after.
     *
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    private function loadShippingConfigWith(array $env): array
    {
        $keys = ['SHIPMENT_POST_STANDARD_PRICE', 'SHIPPING_MISSING_WEIGHT_FALLBACK_ENABLED'];
        $saved = [];

        foreach ($keys as $key) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        try {
            foreach ($env as $key => $value) {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }

            return require config_path('shipping.php');
        } finally {
            foreach ($saved as $key => [$envValue, $serverValue, $putenvValue]) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
                if ($envValue !== null) {
                    $_ENV[$key] = $envValue;
                }
                if ($serverValue !== null) {
                    $_SERVER[$key] = $serverValue;
                }
                if ($putenvValue !== false) {
                    putenv("{$key}={$putenvValue}");
                }
            }
        }
    }
}
