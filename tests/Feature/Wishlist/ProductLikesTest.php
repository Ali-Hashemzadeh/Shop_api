<?php

declare(strict_types=1);

namespace Tests\Feature\Wishlist;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Identity\Domain\Models\User;
use Modules\Wishlist\Domain\Models\ProductLike;
use Tests\TestCase;

class ProductLikesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
    }

    private function createProduct(string $title = 'Phone', string $status = 'published'): Product
    {
        $product = Product::create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.uniqid(),
            'status' => $status,
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-'.$product->id,
            'type' => 'color',
            'is_default' => true,
            'base_price' => 10000000,
        ]);

        return $product->fresh();
    }

    /** @test */
    public function an_authenticated_user_can_like_a_product(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->createProduct();

        $response = $this->postJson("/api/v1/catalog/products/{$product->uuid}/like");

        $response->assertOk()->assertExactJson(['liked' => true]);
        $this->assertDatabaseHas('product_likes', [
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
    }

    /** @test */
    public function an_authenticated_user_can_unlike_a_product(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->createProduct();
        ProductLike::create(['user_id' => $user->id, 'product_id' => $product->id]);

        $response = $this->deleteJson("/api/v1/catalog/products/{$product->uuid}/like");

        $response->assertOk()->assertExactJson(['liked' => false]);
        $this->assertDatabaseMissing('product_likes', [
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
    }

    /** @test */
    public function liking_a_product_twice_does_not_create_duplicate_rows(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->createProduct();

        $this->postJson("/api/v1/catalog/products/{$product->uuid}/like")->assertOk();
        $this->postJson("/api/v1/catalog/products/{$product->uuid}/like")->assertOk();

        $this->assertSame(1, ProductLike::where('user_id', $user->id)->where('product_id', $product->id)->count());
    }

    /** @test */
    public function a_user_cannot_remove_another_users_like(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('customer');
        $product = $this->createProduct();
        ProductLike::create(['user_id' => $owner->id, 'product_id' => $product->id]);

        // A different customer tries to unlike the same product.
        $this->actingAsCustomer();
        $this->deleteJson("/api/v1/catalog/products/{$product->uuid}/like")->assertOk();

        // The owner's like is untouched — unlike is scoped to the caller.
        $this->assertDatabaseHas('product_likes', [
            'user_id' => $owner->id,
            'product_id' => $product->id,
        ]);
    }

    /** @test */
    public function a_user_only_sees_their_own_liked_products(): void
    {
        $productA = $this->createProduct('Alpha');
        $productB = $this->createProduct('Beta');

        $other = User::factory()->create();
        $other->assignRole('customer');
        ProductLike::create(['user_id' => $other->id, 'product_id' => $productB->id]);

        $user = $this->actingAsCustomer();
        ProductLike::create(['user_id' => $user->id, 'product_id' => $productA->id]);

        $response = $this->getJson('/api/v1/catalog/liked-products');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $productA->uuid)
            ->assertJsonPath('data.0.is_liked', true);
    }

    /** @test */
    public function liked_products_are_paginated(): void
    {
        $user = $this->actingAsCustomer();

        for ($i = 0; $i < 3; $i++) {
            $product = $this->createProduct("P{$i}");
            ProductLike::create(['user_id' => $user->id, 'product_id' => $product->id]);
        }

        $response = $this->getJson('/api/v1/catalog/liked-products?per_page=2');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 2);
    }

    /** @test */
    public function liking_a_nonexistent_product_is_rejected(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/catalog/products/bdp-ZZZZZZ/like')->assertNotFound();
    }

    /** @test */
    public function a_guest_cannot_like_a_product(): void
    {
        $product = $this->createProduct();

        $this->postJson("/api/v1/catalog/products/{$product->uuid}/like")->assertUnauthorized();
        $this->getJson('/api/v1/catalog/liked-products')->assertUnauthorized();
    }

    /** @test */
    public function the_product_response_exposes_the_liked_state_per_user(): void
    {
        $product = $this->createProduct();

        // Guest sees is_liked = false and the public read still works.
        $this->getJson("/api/v1/catalog/products/{$product->uuid}")
            ->assertOk()
            ->assertJsonPath('is_liked', false);

        // Authenticated liker sees true.
        $user = $this->actingAsCustomer();
        ProductLike::create(['user_id' => $user->id, 'product_id' => $product->id]);

        $this->getJson("/api/v1/catalog/products/{$product->uuid}")
            ->assertOk()
            ->assertJsonPath('is_liked', true);

        // A different authenticated user sees false.
        $other = User::factory()->create();
        $other->assignRole('customer');
        Sanctum::actingAs($other);

        $this->getJson("/api/v1/catalog/products/{$product->uuid}")
            ->assertOk()
            ->assertJsonPath('is_liked', false);
    }

    /** @test */
    public function the_liked_state_listing_introduces_no_n_plus_one_query(): void
    {
        // A page full of liked products, the user created but not yet acting as.
        $user = User::factory()->create();
        $user->assignRole('customer');

        for ($i = 0; $i < 6; $i++) {
            $product = $this->createProduct("Item{$i}");
            ProductLike::create(['user_id' => $user->id, 'product_id' => $product->id]);
        }

        // Baseline: the same listing as a guest, with no wishlist enrichment.
        DB::enableQueryLog();
        $this->getJson('/api/v1/catalog/products')->assertOk();
        $guestQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        // Same page, now authenticated so is_liked / availability are annotated.
        Sanctum::actingAs($user);

        DB::enableQueryLog();
        $this->getJson('/api/v1/catalog/products')->assertOk();
        $authQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Enrichment adds a small, constant number of batch queries (liked product
        // ids + subscribed SKUs) for the whole six-product page. An N+1 would add a
        // query per product (delta >= 6); batched, the delta stays <= 2.
        $delta = $authQueries - $guestQueries;
        $this->assertGreaterThanOrEqual(1, $delta, 'Enrichment should have run for the authenticated user.');
        $this->assertLessThanOrEqual(2, $delta, 'Wishlist enrichment must not introduce an N+1.');
    }
}
