<?php

declare(strict_types=1);

namespace Tests\Feature\Wishlist;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Identity\Domain\Models\User;
use Modules\Wishlist\Domain\Models\AvailabilitySubscription;
use Tests\TestCase;

class AvailabilitySubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
    }

    private function createProduct(string $status = 'published'): Product
    {
        return Product::create([
            'title' => 'Shirt',
            'slug' => 'shirt-'.uniqid(),
            'status' => $status,
        ]);
    }

    private function createVariant(Product $product, string $sku): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'type' => 'color',
            'is_default' => false,
            'base_price' => 5000000,
        ]);
    }

    private function url(string $sku): string
    {
        return '/api/v1/catalog/variants/sku/'.$sku.'/availability-notification';
    }

    /** @test */
    public function an_authenticated_user_can_subscribe_to_an_unavailable_sku(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->createProduct();
        $this->createVariant($product, 'BLACK-L');

        $response = $this->postJson($this->url('BLACK-L'));

        $response->assertOk()->assertExactJson(['availability_notification_requested' => true]);
        $this->assertDatabaseHas('availability_subscriptions', [
            'user_id' => $user->id,
            'sku' => 'BLACK-L',
            'notified_at' => null,
        ]);
    }

    /** @test */
    public function a_duplicate_subscription_is_idempotent(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->createProduct();
        $this->createVariant($product, 'BLACK-L');

        $this->postJson($this->url('BLACK-L'))->assertOk();
        $this->postJson($this->url('BLACK-L'))->assertOk();

        $this->assertSame(1, AvailabilitySubscription::where('user_id', $user->id)->where('sku', 'BLACK-L')->count());
    }

    /** @test */
    public function a_user_can_cancel_their_subscription(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->createProduct();
        $this->createVariant($product, 'BLACK-L');
        AvailabilitySubscription::create(['user_id' => $user->id, 'sku' => 'BLACK-L']);

        $response = $this->deleteJson($this->url('BLACK-L'));

        $response->assertOk()->assertExactJson(['availability_notification_requested' => false]);
        $this->assertDatabaseMissing('availability_subscriptions', [
            'user_id' => $user->id,
            'sku' => 'BLACK-L',
        ]);
    }

    /** @test */
    public function a_user_cannot_cancel_another_users_subscription(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('customer');
        AvailabilitySubscription::create(['user_id' => $owner->id, 'sku' => 'BLACK-L']);

        $product = $this->createProduct();
        $this->createVariant($product, 'BLACK-L');

        $this->actingAsCustomer();
        $this->deleteJson($this->url('BLACK-L'))->assertOk();

        // The owner's subscription is untouched.
        $this->assertDatabaseHas('availability_subscriptions', [
            'user_id' => $owner->id,
            'sku' => 'BLACK-L',
        ]);
    }

    /** @test */
    public function a_guest_cannot_subscribe(): void
    {
        $product = $this->createProduct();
        $this->createVariant($product, 'BLACK-L');

        $this->postJson($this->url('BLACK-L'))->assertUnauthorized();
    }

    /** @test */
    public function subscribing_to_a_nonexistent_sku_is_rejected(): void
    {
        $this->actingAsCustomer();

        $this->postJson($this->url('NO-SUCH-SKU'))->assertNotFound();
    }

    /** @test */
    public function subscribing_to_a_draft_products_sku_is_rejected(): void
    {
        $this->actingAsCustomer();
        $product = $this->createProduct('draft');
        $this->createVariant($product, 'DRAFT-SKU');

        // A variant of a non-purchasable (draft) product is not subscribable.
        $this->postJson($this->url('DRAFT-SKU'))->assertNotFound();
        $this->assertDatabaseCount('availability_subscriptions', 0);
    }

    /** @test */
    public function a_subscription_is_tied_to_the_exact_sku_not_the_product(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->createProduct();
        $this->createVariant($product, 'BLACK-L');
        $this->createVariant($product, 'WHITE-M');

        $this->postJson($this->url('BLACK-L'))->assertOk();

        // Only the exact SKU is subscribed; its sibling variant is not.
        $this->assertDatabaseHas('availability_subscriptions', ['user_id' => $user->id, 'sku' => 'BLACK-L']);
        $this->assertDatabaseMissing('availability_subscriptions', ['user_id' => $user->id, 'sku' => 'WHITE-M']);
        $this->assertSame(1, AvailabilitySubscription::where('user_id', $user->id)->count());
    }

    /** @test */
    public function the_variant_response_exposes_the_subscription_state_per_user(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->createProduct();
        $this->createVariant($product, 'BLACK-L');

        $this->getJson('/api/v1/catalog/variants/sku/BLACK-L')
            ->assertOk()
            ->assertJsonPath('availability_notification_requested', false);

        AvailabilitySubscription::create(['user_id' => $user->id, 'sku' => 'BLACK-L']);

        $this->getJson('/api/v1/catalog/variants/sku/BLACK-L')
            ->assertOk()
            ->assertJsonPath('availability_notification_requested', true);
    }
}
