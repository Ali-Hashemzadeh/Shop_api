<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Inventory\Domain\Events\InventoryRestockedEvent;
use Tests\TestCase;

/**
 * The restock event fires on exactly one transition — available stock crossing
 * from <= 0 to > 0 — and on nothing else.
 */
class RestockEventTest extends TestCase
{
    use RefreshDatabase;

    private function inventory(): InventoryManagerInterface
    {
        return app(InventoryManagerInterface::class);
    }

    /** @test */
    public function creating_stock_from_zero_to_positive_fires_the_restock_event(): void
    {
        Event::fake([InventoryRestockedEvent::class]);

        $this->inventory()->adjustStock('NEW-SKU', 10, 'restock');

        Event::assertDispatched(InventoryRestockedEvent::class, function (InventoryRestockedEvent $event) {
            return $event->sku === 'NEW-SKU'
                && $event->previousAvailableQuantity === 0
                && $event->newAvailableQuantity === 10;
        });
    }

    /** @test */
    public function restocking_from_zero_to_one_fires_the_event(): void
    {
        Event::fake([InventoryRestockedEvent::class]);

        $this->inventory()->adjustStock('SKU-1', 1, 'restock');

        Event::assertDispatched(InventoryRestockedEvent::class);
    }

    /** @test */
    public function topping_up_positive_stock_does_not_fire_the_event(): void
    {
        // Seed to 10 (this first move legitimately fires 0 -> 10).
        $this->inventory()->adjustStock('SKU-2', 10, 'restock');

        Event::fake([InventoryRestockedEvent::class]);
        $this->inventory()->adjustStock('SKU-2', 5, 'restock'); // 10 -> 15

        Event::assertNotDispatched(InventoryRestockedEvent::class);
    }

    /** @test */
    public function selling_positive_stock_down_to_zero_does_not_fire_the_event(): void
    {
        $this->inventory()->adjustStock('SKU-3', 10, 'restock');

        Event::fake([InventoryRestockedEvent::class]);
        $this->inventory()->adjustStock('SKU-3', -10, 'sale'); // 10 -> 0

        Event::assertNotDispatched(InventoryRestockedEvent::class);
    }

    /** @test */
    public function a_zero_to_zero_no_op_does_not_fire_the_event(): void
    {
        $this->inventory()->adjustStock('SKU-4', 0, 'adjustment'); // stays 0

        Event::fake([InventoryRestockedEvent::class]);
        $this->inventory()->adjustStock('SKU-4', 0, 'adjustment'); // 0 -> 0

        Event::assertNotDispatched(InventoryRestockedEvent::class);
    }

    /** @test */
    public function releasing_a_reservation_back_into_availability_fires_the_event(): void
    {
        // 5 physical, fully reserved by an order → available 0.
        $this->inventory()->adjustStock('SKU-5', 5, 'restock');
        $this->inventory()->reserveStock('SKU-5', 5, orderId: 1);

        Event::fake([InventoryRestockedEvent::class]);
        $this->inventory()->releaseReservation('SKU-5', 5, orderId: 1); // available 0 -> 5

        Event::assertDispatched(InventoryRestockedEvent::class, function (InventoryRestockedEvent $event) {
            return $event->sku === 'SKU-5' && $event->newAvailableQuantity === 5;
        });
    }
}
