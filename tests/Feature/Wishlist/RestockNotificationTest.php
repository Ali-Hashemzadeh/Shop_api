<?php

declare(strict_types=1);

namespace Tests\Feature\Wishlist;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Identity\Domain\Models\User;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Inventory\Domain\Events\InventoryRestockedEvent;
use Modules\Notification\Domain\Models\Notification;
use Modules\Sms\Infrastructure\Drivers\FakeSmsProvider;
use Modules\Wishlist\Domain\Models\AvailabilitySubscription;
use Tests\TestCase;

/**
 * Restock → InventoryRestockedEvent → SendProductAvailableNotifications →
 * in-app + SMS → subscription consumed.
 */
class RestockNotificationTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsProvider $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();

        config()->set('sms.default', 'fake');
        $this->sms = app(FakeSmsProvider::class);
        $this->sms->reset();
    }

    private function inventory(): InventoryManagerInterface
    {
        return app(InventoryManagerInterface::class);
    }

    private function productWithVariant(string $sku, string $title = 'Cool Shirt'): Product
    {
        $product = Product::create([
            'title' => $title,
            'slug' => 'cool-shirt-'.uniqid(),
            'status' => 'published',
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'type' => 'color',
            'is_default' => true,
            'base_price' => 5000000,
        ]);

        return $product->fresh();
    }

    private function subscribe(int $userId, string $sku): void
    {
        AvailabilitySubscription::create(['user_id' => $userId, 'sku' => $sku]);
    }

    /** @test */
    public function a_restock_notifies_every_subscribed_user_in_app_and_by_sms_and_consumes_the_subscription(): void
    {
        $product = $this->productWithVariant('BLACK-L');

        $userA = User::factory()->create(['phone' => '09120000001']);
        $userB = User::factory()->create(['phone' => '09120000002']);
        $this->subscribe($userA->id, 'BLACK-L');
        $this->subscribe($userB->id, 'BLACK-L');

        $this->inventory()->adjustStock('BLACK-L', 7, 'restock'); // 0 -> 7

        foreach ([$userA, $userB] as $user) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $user->id,
                'type' => 'product_available',
                'title' => 'محصول موجود شد',
                'message' => 'محصول «Cool Shirt» دوباره موجود شده است.',
            ]);
        }

        // The in-app payload carries the public product code and the SKU.
        $notification = Notification::where('user_id', $userA->id)->where('type', 'product_available')->first();
        $this->assertSame($product->uuid, $notification->data['product_code']);
        $this->assertSame('BLACK-L', $notification->data['sku']);

        // Each subscriber got an SMS attempt with our internal template + parameter name.
        $this->assertCount(2, $this->sms->sent());
        $this->assertSame('product_available', $this->sms->lastMessage()->template);
        $this->assertSame(['ProductName' => 'Cool Shirt'], $this->sms->lastMessage()->parameters);
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'sms', 'status' => 'sent']);

        // One-shot: both subscriptions are now consumed.
        $this->assertSame(0, AvailabilitySubscription::whereNull('notified_at')->count());
        $this->assertSame(2, AvailabilitySubscription::whereNotNull('notified_at')->count());
    }

    /** @test */
    public function users_without_a_subscription_receive_nothing(): void
    {
        $this->productWithVariant('BLACK-L');

        $subscriber = User::factory()->create(['phone' => '09120000001']);
        $bystander = User::factory()->create(['phone' => '09120000009']);
        $this->subscribe($subscriber->id, 'BLACK-L');

        $this->inventory()->adjustStock('BLACK-L', 3, 'restock');

        $this->assertDatabaseHas('notifications', ['user_id' => $subscriber->id, 'type' => 'product_available']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $bystander->id, 'type' => 'product_available']);
        $this->assertCount(1, $this->sms->sent());
    }

    /** @test */
    public function a_restock_of_a_different_sku_does_not_notify_a_subscriber_of_another_sku(): void
    {
        $this->productWithVariant('BLACK-L');
        $this->productWithVariant('WHITE-M', 'Other Shirt');

        $user = User::factory()->create(['phone' => '09120000001']);
        $this->subscribe($user->id, 'BLACK-L');

        // A sibling SKU is restocked; the BLACK-L subscriber must not be notified.
        $this->inventory()->adjustStock('WHITE-M', 5, 'restock');

        $this->assertDatabaseMissing('notifications', ['user_id' => $user->id, 'type' => 'product_available']);
        $this->assertDatabaseHas('availability_subscriptions', [
            'user_id' => $user->id,
            'sku' => 'BLACK-L',
            'notified_at' => null,
        ]);
    }

    /** @test */
    public function processing_the_same_restock_event_twice_does_not_duplicate_notifications(): void
    {
        $this->productWithVariant('BLACK-L');
        $user = User::factory()->create(['phone' => '09120000001']);
        $this->subscribe($user->id, 'BLACK-L');

        // Simulate a retried / duplicated delivery of the identical event.
        $event = new InventoryRestockedEvent('BLACK-L', 0, 5);
        event($event);
        event($event);

        $this->assertSame(
            1,
            Notification::where('user_id', $user->id)->where('type', 'product_available')->count()
        );
        $this->assertCount(1, $this->sms->sent());
    }

    /** @test */
    public function an_sms_failure_does_not_prevent_the_in_app_notification_or_the_restock(): void
    {
        $this->sms->shouldFail = true;

        $this->productWithVariant('BLACK-L');
        $user = User::factory()->create(['phone' => '09120000001']);
        $this->subscribe($user->id, 'BLACK-L');

        $stock = $this->inventory()->adjustStock('BLACK-L', 4, 'restock');

        // Restock itself succeeded.
        $this->assertSame(4, $stock->availableQuantity);
        $this->assertDatabaseHas('inventory_stocks', ['sku' => 'BLACK-L', 'quantity' => 4]);

        // In-app notification still landed; the SMS is recorded as failed, not thrown.
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'type' => 'product_available']);
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'sms', 'status' => 'failed']);

        // Subscription is still consumed (the in-app delivery is the source of truth).
        $this->assertSame(0, AvailabilitySubscription::whereNull('notified_at')->count());
    }

    /** @test */
    public function a_missing_sms_template_skips_the_sms_but_still_delivers_in_app(): void
    {
        config()->set('sms.default', 'smsir');
        config()->set('sms.providers.smsir.api_key', 'test-key');
        config()->set('sms.providers.smsir.templates.product_available', null);

        $this->productWithVariant('BLACK-L');
        $user = User::factory()->create(['phone' => '09120000001']);
        $this->subscribe($user->id, 'BLACK-L');

        $this->inventory()->adjustStock('BLACK-L', 2, 'restock');

        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'type' => 'product_available']);
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'sms', 'status' => 'skipped']);
        $this->assertDatabaseMissing('notification_deliveries', ['status' => 'failed']);
    }

    /** @test */
    public function a_rolled_back_restock_transaction_notifies_nobody(): void
    {
        $this->productWithVariant('BLACK-L');
        $user = User::factory()->create(['phone' => '09120000001']);
        $this->subscribe($user->id, 'BLACK-L');

        try {
            DB::transaction(function () {
                $this->inventory()->adjustStock('BLACK-L', 5, 'restock');
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        // The after-commit listener never ran: no notification, subscription intact,
        // and the stock change was rolled back.
        $this->assertDatabaseMissing('notifications', ['user_id' => $user->id, 'type' => 'product_available']);
        $this->assertDatabaseHas('availability_subscriptions', [
            'user_id' => $user->id,
            'sku' => 'BLACK-L',
            'notified_at' => null,
        ]);
        $this->assertDatabaseMissing('inventory_stocks', ['sku' => 'BLACK-L']);
        $this->assertSame([], $this->sms->sent());
    }
}
