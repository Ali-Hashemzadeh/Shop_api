<?php

declare(strict_types=1);

namespace Modules\Notification\Application\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Inventory\Domain\Events\InventoryRestockedEvent;
use Modules\Notification\Domain\Contracts\NotificationManagerInterface;
use Modules\Notification\Domain\DTOs\NotificationRequestDTO;
use Modules\Notification\Domain\DTOs\SmsPayloadDTO;
use Modules\Notification\Domain\Enums\NotificationChannel;
use Modules\Notification\Domain\Enums\NotificationTemplate;
use Modules\Notification\Domain\Enums\NotificationType;
use Modules\Wishlist\Domain\Contracts\WishlistManagerInterface;

/**
 * A SKU came back in stock: notify every customer who subscribed to *that exact
 * SKU* — in-app + SMS — then consume their one-shot subscription.
 *
 * ShouldHandleEventsAfterCommit: the restock event is dispatched inside the
 * Inventory transaction, so this runs only once that transaction has committed.
 * A rolled-back restock therefore notifies nobody (the stock the customer would
 * be told about never actually existed).
 *
 * Idempotency + concurrency: the subscriber list is claimed atomically by
 * Wishlist (each subscription is stamped consumed under a row lock before we
 * send), so a duplicated or retried event, or a second worker, finds nothing
 * left to claim and no customer is notified twice. We therefore need no
 * event-id dedup table of our own.
 *
 * The SMS leg is best-effort, exactly like every other notification here: an
 * unconfigured template or a provider failure is recorded as skipped/failed by
 * SmsChannel and never thrown, so the in-app notification always lands and the
 * restock is never affected.
 */
class SendProductAvailableNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly NotificationManagerInterface $notifications,
        private readonly WishlistManagerInterface $wishlist,
        private readonly CatalogManagerInterface $catalog,
    ) {}

    public function handle(InventoryRestockedEvent $event): void
    {
        // Claim first: this both selects the audience and consumes their
        // subscriptions, so the one-shot and no-duplicate guarantees hold even
        // if this event is delivered again.
        $userIds = $this->wishlist->claimPendingSubscribersForSku($event->sku);

        if ($userIds === []) {
            return;
        }

        // Inventory's event carries only the SKU; resolve the product name and
        // public code through Catalog's contract for the copy and the deep link.
        $variant = $this->catalog->findVariantBySku($event->sku);
        $productName = $variant?->productName ?? 'محصول';
        $productCode = $variant?->productPublicCode;

        foreach ($userIds as $userId) {
            $this->notifications->send(new NotificationRequestDTO(
                userId: $userId,
                type: NotificationType::PRODUCT_AVAILABLE->value,
                title: 'محصول موجود شد',
                message: "محصول «{$productName}» دوباره موجود شده است.",
                data: [
                    'product_code' => $productCode,
                    'sku' => $event->sku,
                ],
                channels: [NotificationChannel::DATABASE, NotificationChannel::SMS],
                sms: new SmsPayloadDTO(NotificationTemplate::PRODUCT_AVAILABLE, ['ProductName' => $productName]),
            ));
        }
    }
}
