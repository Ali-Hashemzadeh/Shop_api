<?php

declare(strict_types=1);

namespace Tests\Feature\PublicCode;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Catalog\Domain\Models\Category;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Catalog\Infrastructure\Persistence\Seeders\CatalogSampleDataSeeder;
use Modules\Catalog\Infrastructure\Persistence\Seeders\CategoryTreeSeeder;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Domain\Models\User;
use Modules\Order\Domain\Models\Order;
use Modules\Payment\Domain\Models\Payment;
use Modules\Shipment\Domain\Models\Shipment;
use Tests\TestCase;

/**
 * Cross-module coverage for the customer-facing public-code scheme: generation
 * per entity, immutability, legacy-identifier compatibility, the new search
 * surfaces (including ownership isolation), and the database constraints that
 * back it all up.
 */
class PublicCodeTest extends TestCase
{
    use RefreshDatabase;

    /** The six approved suffix characters, as a character class. */
    private const SUFFIX = '[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedCatalogPermissions();
        $this->seedOrderPermissions();
    }

    private function assertCode(string $prefix, ?string $value, string $context): void
    {
        $this->assertNotNull($value, "{$context} has no public code.");
        $this->assertMatchesRegularExpression('/^'.preg_quote($prefix, '/').self::SUFFIX.'$/', $value, $context);
    }

    // ── 1-2. Catalog: product code + variant SKU ──────────────────────────────

    /** @test */
    public function a_new_product_receives_a_bdp_code_and_its_variants_receive_bdv_skus(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/catalog/products', [
            'title' => 'Coded Product',
            'status' => 'published',
            'variants' => [
                ['type' => 'color', 'base_price' => 10000, 'is_default' => true],
                ['type' => 'color', 'base_price' => 20000, 'is_default' => false],
            ],
        ])->assertCreated();

        $this->assertCode('bdp-', $response->json('id'), 'product');

        $product = Product::where('uuid', $response->json('id'))->sole();
        $skus = ProductVariant::where('product_id', $product->id)->pluck('sku');

        $this->assertCount(2, $skus);
        foreach ($skus as $sku) {
            $this->assertCode('bdv-', $sku, 'variant sku');
        }

        // Independently generated, not derived from position or the product id.
        $this->assertCount(2, array_unique($skus->all()));
    }

    /** @test */
    public function a_standalone_variant_also_receives_a_bdv_sku(): void
    {
        $this->actingAsAdmin();
        $product = Product::create(['title' => 'Host', 'slug' => 'host', 'status' => 'published']);

        $sku = $this->postJson("/api/v1/catalog/products/{$product->uuid}/variants", [
            'type' => 'color',
            'base_price' => 5000,
        ])->assertCreated()->json('sku');

        $this->assertCode('bdv-', $sku, 'standalone variant sku');
    }

    // ── 3-7. New codes on the remaining entities ──────────────────────────────

    /** @test */
    public function a_new_category_receives_a_unique_bdc_code(): void
    {
        $this->actingAsAdmin();

        $first = $this->postJson('/api/v1/catalog/categories', ['name' => 'Shoes'])->assertCreated();
        $second = $this->postJson('/api/v1/catalog/categories', ['name' => 'Hats'])->assertCreated();

        $this->assertCode('bdc-', $first->json('public_code'), 'category');
        $this->assertCode('bdc-', $second->json('public_code'), 'category');
        $this->assertNotSame($first->json('public_code'), $second->json('public_code'));

        // The numeric id is still the identifier in the response.
        $this->assertIsInt($first->json('id'));
    }

    /** @test */
    public function a_new_order_receives_a_unique_bdo_code(): void
    {
        $user = User::factory()->create();

        $first = $this->makeOrder($user->id);
        $second = $this->makeOrder($user->id);

        $this->assertCode('bdo-', $first->public_code, 'order');
        $this->assertCode('bdo-', $second->public_code, 'order');
        $this->assertNotSame($first->public_code, $second->public_code);
    }

    /** @test */
    public function a_new_payment_receives_a_unique_bdt_code_without_touching_the_gateway_reference(): void
    {
        $first = Payment::create([
            'order_id' => 1, 'method_type' => 'online', 'gateway' => 'zarinpal',
            'transaction_reference' => 'AUTH-1', 'amount' => 1000, 'status' => 'initiated',
        ]);
        $second = Payment::create([
            'order_id' => 2, 'method_type' => 'online', 'gateway' => 'zarinpal',
            'transaction_reference' => 'AUTH-2', 'amount' => 1000, 'status' => 'initiated',
        ]);

        $this->assertCode('bdt-', $first->public_code, 'payment');
        $this->assertNotSame($first->public_code, $second->public_code);

        // The gateway authority is a separate concern and is left alone.
        $this->assertSame('AUTH-1', $first->transaction_reference);
    }

    /** @test */
    public function a_new_shipment_receives_a_unique_bds_code(): void
    {
        $first = $this->makeShipment(101);
        $second = $this->makeShipment(102);

        $this->assertCode('bds-', $first->public_code, 'shipment');
        $this->assertNotSame($first->public_code, $second->public_code);
    }

    /** @test */
    public function a_new_address_receives_a_unique_bda_code(): void
    {
        $user = $this->actingAsCustomer();
        [$provinceId, $cityId] = $this->createProvinceCity();

        $base = [
            'province_id' => $provinceId,
            'city_id' => $cityId,
            'latitude' => 35.7,
            'longitude' => 51.4,
        ];

        $first = $this->postJson('/api/v1/addresses', $base + ['title' => 'Home', 'address' => 'Street 1'])
            ->assertCreated()
            ->json('data.public_code');

        $second = $this->postJson('/api/v1/addresses', $base + ['title' => 'Work', 'address' => 'Street 2'])
            ->assertCreated()
            ->json('data.public_code');

        $this->assertCode('bda-', $first, 'address');
        $this->assertNotSame($first, $second);
        $this->assertSame(2, Address::where('user_id', $user->id)->count());
    }

    // ── 8. Immutability / not client-writable ─────────────────────────────────

    /** @test */
    public function generated_identifiers_are_never_accepted_from_client_input(): void
    {
        $this->actingAsAdmin();

        // Product: a submitted uuid is ignored.
        $uuid = $this->postJson('/api/v1/catalog/products', [
            'title' => 'Spoofed', 'status' => 'draft', 'uuid' => 'bdp-AAAAAA',
        ])->assertCreated()->json('id');
        $this->assertNotSame('bdp-AAAAAA', $uuid);

        // Variant: a submitted sku is ignored.
        $product = Product::where('uuid', $uuid)->sole();
        $sku = $this->postJson("/api/v1/catalog/products/{$product->uuid}/variants", [
            'type' => 'color', 'base_price' => 100, 'sku' => 'CLIENT-SKU',
        ])->assertCreated()->json('sku');
        $this->assertNotSame('CLIENT-SKU', $sku);
        $this->assertCode('bdv-', $sku, 'variant sku');

        // Category: a submitted public_code is ignored.
        $categoryCode = $this->postJson('/api/v1/catalog/categories', [
            'name' => 'Spoofed Category', 'public_code' => 'bdc-AAAAAA',
        ])->assertCreated()->json('public_code');
        $this->assertNotSame('bdc-AAAAAA', $categoryCode);
    }

    /** @test */
    public function updates_never_regenerate_or_overwrite_an_existing_identifier(): void
    {
        $this->actingAsAdmin();

        $product = Product::create(['title' => 'Stable', 'slug' => 'stable', 'status' => 'draft']);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'legacy-sku-1',
            'type' => 'color', 'base_price' => 1000, 'is_default' => true,
        ]);
        $category = Category::create(['name' => 'Stable Cat', 'slug' => 'stable-cat']);

        $originalUuid = $product->uuid;
        $originalCategoryCode = $category->public_code;

        $this->patchJson("/api/v1/catalog/products/{$product->uuid}", [
            'title' => 'Renamed',
            'variants' => [[
                'id' => $variant->id, 'type' => 'color', 'base_price' => 2000,
                'is_default' => true, 'sku' => 'HACKED',
            ]],
        ])->assertOk();

        $this->patchJson("/api/v1/catalog/categories/{$category->id}", [
            'name' => 'Renamed Cat', 'public_code' => 'bdc-ZZZZZZ',
        ])->assertOk();

        $this->assertSame($originalUuid, $product->fresh()->uuid);
        $this->assertSame('legacy-sku-1', $variant->fresh()->sku);
        $this->assertSame($originalCategoryCode, $category->fresh()->public_code);
    }

    /** @test */
    public function changing_the_namespace_affects_only_newly_generated_codes(): void
    {
        $existing = Category::create(['name' => 'Before', 'slug' => 'before']);
        $this->assertCode('bdc-', $existing->public_code, 'category');

        config()->set('public_codes.namespace', 'zz');
        $created = Category::create(['name' => 'After', 'slug' => 'after']);

        $this->assertStringStartsWith('zzc-', $created->public_code);
        // The already-issued code is untouched by the configuration change.
        $this->assertSame($existing->public_code, $existing->fresh()->public_code);
    }

    // ── 9-10. Legacy identifiers keep resolving ───────────────────────────────

    /** @test */
    public function a_legacy_seven_character_hex_product_uuid_still_resolves(): void
    {
        $product = Product::create(['title' => 'Old', 'slug' => 'old', 'status' => 'published']);
        // Simulate a product issued before the public-code scheme.
        $product->forceFill(['uuid' => 'a3f9c1b'])->save();

        $this->getJson('/api/v1/catalog/products/a3f9c1b')
            ->assertOk()
            ->assertJsonPath('id', 'a3f9c1b');
    }

    /** @test */
    public function a_new_format_product_code_resolves_and_does_not_shadow_reserved_routes(): void
    {
        $product = Product::create(['title' => 'New', 'slug' => 'new', 'status' => 'published']);

        $this->getJson("/api/v1/catalog/products/{$product->uuid}")
            ->assertOk()
            ->assertJsonPath('id', $product->uuid);

        // `/products/admin` is a real endpoint, not a product code — unauthenticated
        // it must be 401 from the auth middleware, never a product 404.
        $this->getJson('/api/v1/catalog/products/admin')->assertStatus(401);
    }

    /** @test */
    public function a_legacy_sh_shipment_code_still_resolves(): void
    {
        $this->seedShipmentPermissions();
        $user = $this->actingAsAdmin();

        $shipment = $this->makeShipment(555, $user->id);
        $shipment->forceFill(['public_code' => 'SH-X0T3K9ABCD'])->save();

        $this->getJson('/api/v1/shipments/SH-X0T3K9ABCD')
            ->assertOk()
            ->assertJsonPath('id', 'SH-X0T3K9ABCD');
    }

    /** @test */
    public function a_new_format_shipment_code_resolves(): void
    {
        $this->seedShipmentPermissions();
        $user = $this->actingAsAdmin();

        $shipment = $this->makeShipment(556, $user->id);
        $this->assertCode('bds-', $shipment->public_code, 'shipment');

        $this->getJson("/api/v1/shipments/{$shipment->public_code}")
            ->assertOk()
            ->assertJsonPath('id', $shipment->public_code);
    }

    // ── 11-12. Product and variant public-code search ─────────────────────────

    /** @test */
    public function public_product_search_finds_a_product_by_its_own_code(): void
    {
        $wanted = Product::create(['title' => 'Wanted', 'slug' => 'wanted', 'status' => 'published']);
        Product::create(['title' => 'Other', 'slug' => 'other', 'status' => 'published']);

        // Lowercase input still resolves — the term is normalized before matching.
        $response = $this->getJson('/api/v1/catalog/products?search='.strtolower($wanted->uuid))
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($wanted->uuid, $response->json('data.0.id'));
    }

    /** @test */
    public function public_product_search_finds_the_owning_product_by_variant_sku(): void
    {
        $product = Product::create(['title' => 'Has Variant', 'slug' => 'has-variant', 'status' => 'published']);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'type' => 'color', 'base_price' => 1000, 'is_default' => true,
        ]);
        Product::create(['title' => 'Unrelated', 'slug' => 'unrelated', 'status' => 'published']);

        $this->assertCode('bdv-', $variant->sku, 'variant sku');

        $response = $this->getJson('/api/v1/catalog/products?search='.$variant->sku)->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($product->uuid, $response->json('data.0.id'));
    }

    /** @test */
    public function ordinary_product_search_terms_still_match_on_title(): void
    {
        Product::create(['title' => 'Blue Shirt', 'slug' => 'blue-shirt', 'status' => 'published']);
        Product::create(['title' => 'Red Hat', 'slug' => 'red-hat', 'status' => 'published']);

        $response = $this->getJson('/api/v1/catalog/products?search=Blue')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Blue Shirt', $response->json('data.0.title'));
    }

    // ── 13-14. Ownership isolation on the customer searches ───────────────────

    /** @test */
    public function customer_order_search_matches_own_order_and_never_another_customers(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $mine = $this->makeOrder($me->id);
        $theirs = $this->makeOrder($other->id);

        $this->actingAsCustomer($me);

        // Own code, typed in the wrong case — still found.
        $this->getJson('/api/v1/orders?search='.strtoupper($mine->public_code))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_code', $mine->public_code);

        // Another customer's real code returns nothing, and the response is
        // indistinguishable from a code that does not exist at all.
        $foreign = $this->getJson('/api/v1/orders?search='.$theirs->public_code)->assertOk();
        $missing = $this->getJson('/api/v1/orders?search=bdo-ZZZZZZ')->assertOk();

        $this->assertCount(0, $foreign->json('data'));
        $this->assertSame($missing->json('data'), $foreign->json('data'));
        $this->assertSame($missing->json('meta.total'), $foreign->json('meta.total'));
    }

    /** @test */
    public function an_unsearched_order_list_keeps_its_existing_ordering(): void
    {
        $me = User::factory()->create();
        $older = $this->makeOrder($me->id);
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $newer = $this->makeOrder($me->id);

        $this->actingAsCustomer($me);

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id);
    }

    /** @test */
    public function customer_address_search_matches_own_address_and_never_another_users(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $mine = Address::create(['user_id' => $me->id, 'title' => 'Mine', 'address' => 'A']);
        $theirs = Address::create(['user_id' => $other->id, 'title' => 'Theirs', 'address' => 'B']);

        $this->actingAsCustomer($me);

        $this->getJson('/api/v1/addresses?search='.strtoupper($mine->public_code))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_code', $mine->public_code);

        $foreign = $this->getJson('/api/v1/addresses?search='.$theirs->public_code)->assertOk();
        $missing = $this->getJson('/api/v1/addresses?search=bda-ZZZZZZ')->assertOk();

        $this->assertCount(0, $foreign->json('data'));
        $this->assertSame($missing->json('data'), $foreign->json('data'));
    }

    // ── 15. Public category search ────────────────────────────────────────────

    /** @test */
    public function public_category_search_matches_the_exact_category_code(): void
    {
        $wanted = Category::create(['name' => 'Wanted', 'slug' => 'wanted-cat', 'is_active' => true]);
        Category::create(['name' => 'Other', 'slug' => 'other-cat', 'is_active' => true]);

        $response = $this->getJson('/api/v1/catalog/categories/roots?search='.strtolower($wanted->public_code))
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($wanted->id, $response->json('data.0.id'));
        $this->assertSame($wanted->public_code, $response->json('data.0.public_code'));

        // A partial code must not match — public codes are looked up whole.
        $partial = substr($wanted->public_code, 0, 8);
        $this->assertCount(0, $this->getJson('/api/v1/catalog/categories/roots?search='.$partial)->json('data'));
    }

    /** @test */
    public function an_unsearched_category_list_still_returns_only_roots(): void
    {
        $root = Category::create(['name' => 'Root', 'slug' => 'root', 'is_active' => true]);
        $child = Category::create(['name' => 'Child', 'slug' => 'child', 'is_active' => true, 'parent_id' => $root->id]);

        $ids = collect($this->getJson('/api/v1/catalog/categories/roots')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertSame([$root->id], $ids);

        // …but a child is still reachable by its own code.
        $this->assertSame(
            $child->id,
            $this->getJson('/api/v1/catalog/categories/roots?search='.$child->public_code)->json('data.0.id'),
        );
    }

    // ── 16. Database constraints ──────────────────────────────────────────────

    /** @test */
    public function every_public_code_column_carries_a_unique_index(): void
    {
        $columns = [
            'products' => 'uuid',
            'product_variants' => 'sku',
            'orders' => 'public_code',
            'payments' => 'public_code',
            'shipments' => 'public_code',
            'addresses' => 'public_code',
            'categories' => 'public_code',
        ];

        foreach ($columns as $table => $column) {
            $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column} is missing.");

            $unique = collect(Schema::getIndexes($table))
                ->contains(fn (array $index) => $index['unique'] && $index['columns'] === [$column]);

            $this->assertTrue($unique, "{$table}.{$column} has no unique index.");
        }
    }

    /** @test */
    public function the_database_rejects_a_duplicate_code_even_if_the_application_check_is_bypassed(): void
    {
        $first = Category::create(['name' => 'One', 'slug' => 'one']);

        $this->expectException(QueryException::class);

        // Deliberately bypasses the model's uniqueness check to prove the index —
        // not the application layer — is the final authority.
        DB::table('categories')->insert([
            'public_code' => $first->public_code,
            'name' => 'Two',
            'slug' => 'two',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── 17. Seeders under disabled model events ───────────────────────────────

    /** @test */
    public function seeders_still_assign_identifiers_when_model_events_are_disabled(): void
    {
        // Exactly what DatabaseSeeder's WithoutModelEvents does: the creating hook
        // that normally assigns codes is muted for the whole run.
        Category::unsetEventDispatcher();
        Product::unsetEventDispatcher();
        ProductVariant::unsetEventDispatcher();

        $this->seed(CategoryTreeSeeder::class);
        $this->seed(CatalogSampleDataSeeder::class);

        $this->assertGreaterThan(0, Category::count());
        $this->assertGreaterThan(0, Product::count());
        $this->assertGreaterThan(0, ProductVariant::count());

        $this->assertSame(0, Category::whereNull('public_code')->count());
        $this->assertSame(0, Product::whereNull('uuid')->count());
        $this->assertSame(0, ProductVariant::whereNull('sku')->count());

        foreach (Product::pluck('uuid') as $uuid) {
            $this->assertCode('bdp-', $uuid, 'seeded product');
        }
        foreach (ProductVariant::pluck('sku') as $sku) {
            $this->assertCode('bdv-', $sku, 'seeded variant');
        }
        foreach (Category::pluck('public_code') as $code) {
            $this->assertCode('bdc-', $code, 'seeded category');
        }
    }

    // ── 18. Internal identifiers keep working ─────────────────────────────────

    /** @test */
    public function numeric_ids_foreign_keys_and_skus_remain_the_internal_identifiers(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user->id);
        $shipment = $this->makeShipment($order->id, $user->id);

        // Order keeps its integer id, and the shipment still links by it.
        $this->assertIsInt($order->id);
        $this->assertSame($order->id, $shipment->order_id);

        // Variants still join to products by integer id while exposing a code SKU.
        $product = Product::create(['title' => 'Linked', 'slug' => 'linked', 'status' => 'published']);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'type' => 'color', 'base_price' => 100, 'is_default' => true,
        ]);

        $this->assertSame($product->id, $variant->product->id);
        $this->assertCode('bdv-', $variant->sku, 'variant sku');

        // The same suffix may legitimately appear under two different prefixes,
        // because the full codes differ.
        $suffix = substr($order->public_code, 4);
        $category = Category::create(['name' => 'Twin', 'slug' => 'twin']);
        $category->forceFill(['public_code' => 'bdc-'.$suffix])->save();

        $this->assertSame('bdc-'.$suffix, $category->fresh()->public_code);
        $this->assertSame($order->public_code, $order->fresh()->public_code);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeOrder(int $userId): Order
    {
        return Order::create([
            'user_id' => $userId,
            'status' => 'pending',
            'total_amount' => 1000,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'shipping_address' => ['address' => 'Test Street'],
        ]);
    }

    /** @return array{0: int, 1: int} province id, city id */
    private function createProvinceCity(): array
    {
        $provinceId = DB::table('provinces')->insertGetId([
            'name' => 'Tehran', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cityId = DB::table('cities')->insertGetId([
            'province_id' => $provinceId, 'name' => 'Tehran', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$provinceId, $cityId];
    }

    private function makeShipment(int $orderId, int $userId = 1): Shipment
    {
        return Shipment::create([
            'order_id' => $orderId,
            'user_id' => $userId,
            'method_code' => 'post_standard',
            'method_title' => 'Post',
            'method_type' => 'postal',
            'shipping_cost' => 0,
            'status' => 'pending',
        ]);
    }
}
