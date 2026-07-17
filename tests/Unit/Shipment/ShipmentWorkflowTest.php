<?php

declare(strict_types=1);

namespace Tests\Unit\Shipment;

use Modules\Shipment\Domain\Enums\ShipmentStatus;
use Modules\Shipment\Domain\Exceptions\InvalidShipmentTransitionException;
use Modules\Shipment\Domain\Workflows\LocalDeliveryShipmentWorkflow;
use Modules\Shipment\Domain\Workflows\PickupShipmentWorkflow;
use Modules\Shipment\Domain\Workflows\PostalShipmentWorkflow;
use Modules\Shipment\Domain\Workflows\ShipmentWorkflowResolver;
use PHPUnit\Framework\TestCase;

class ShipmentWorkflowTest extends TestCase
{
    /** @test */
    public function resolver_returns_the_workflow_for_each_method_type(): void
    {
        $resolver = new ShipmentWorkflowResolver;

        $this->assertInstanceOf(PostalShipmentWorkflow::class, $resolver->forType('postal'));
        $this->assertInstanceOf(LocalDeliveryShipmentWorkflow::class, $resolver->forType('local_delivery'));
        $this->assertInstanceOf(PickupShipmentWorkflow::class, $resolver->forType('pickup'));
    }

    /** @test */
    public function postal_handed_to_post_is_terminal(): void
    {
        $workflow = new PostalShipmentWorkflow;

        $this->assertTrue($workflow->canTransition(ShipmentStatus::ReadyForPost, ShipmentStatus::HandedToPost));
        $this->assertEmpty($workflow->transitions()[ShipmentStatus::HandedToPost->value]);
        $this->assertFalse($workflow->canTransition(ShipmentStatus::Pending, ShipmentStatus::OutForDelivery));
    }

    /** @test */
    public function local_delivery_supports_failure_and_reschedule_branches(): void
    {
        $workflow = new LocalDeliveryShipmentWorkflow;

        $this->assertTrue($workflow->canTransition(ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered));
        $this->assertTrue($workflow->canTransition(ShipmentStatus::OutForDelivery, ShipmentStatus::DeliveryFailed));
        $this->assertTrue($workflow->canTransition(ShipmentStatus::DeliveryFailed, ShipmentStatus::ReadyForDispatch));
    }

    /** @test */
    public function assert_can_transition_throws_on_invalid_move(): void
    {
        $this->expectException(InvalidShipmentTransitionException::class);

        (new PickupShipmentWorkflow)->assertCanTransition(ShipmentStatus::Pending, ShipmentStatus::PickedUp);
    }

    /** @test */
    public function shipment_status_maps_to_the_expected_order_summary_status(): void
    {
        $this->assertSame('paid', ShipmentStatus::Pending->toOrderStatus());
        $this->assertSame('processing', ShipmentStatus::Preparing->toOrderStatus());
        $this->assertSame('processing', ShipmentStatus::ReadyForPost->toOrderStatus());
        $this->assertSame('shipped', ShipmentStatus::HandedToPost->toOrderStatus());
        $this->assertSame('shipped', ShipmentStatus::OutForDelivery->toOrderStatus());
        $this->assertSame('completed', ShipmentStatus::Delivered->toOrderStatus());
        $this->assertSame('completed', ShipmentStatus::PickedUp->toOrderStatus());
        $this->assertNull(ShipmentStatus::Cancelled->toOrderStatus());
    }
}
