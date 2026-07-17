<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Modules\Shipment\Domain\Models\Shipment;

class AdminShipmentIndexTest extends ShipmentTestCase
{
    private function seedShipments(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Shipment::create([
                'public_code' => Shipment::generateUniquePublicCode(),
                'order_id' => 1000 + $i,
                'user_id' => 1,
                'method_code' => $i % 2 === 0 ? 'post_standard' : 'in_person_pickup',
                'method_title' => 'X',
                'method_type' => $i % 2 === 0 ? 'postal' : 'pickup',
                'shipping_cost' => 0,
                'status' => 'pending',
            ]);
        }
    }

    /** @test */
    public function admin_index_returns_paginator_envelope_with_default_page_size(): void
    {
        $this->actingAsOperator();
        $this->seedShipments(20);

        $this->getJson('/api/v1/admin/shipments')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonCount(15, 'data');
    }

    /** @test */
    public function admin_index_clamps_per_page_to_100_and_filters_by_method_type(): void
    {
        $this->actingAsOperator();
        $this->seedShipments(6);

        $this->getJson('/api/v1/admin/shipments?per_page=500')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);

        $this->getJson('/api/v1/admin/shipments?method_type=pickup')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    /** @test */
    public function admin_index_requires_the_view_admin_permission(): void
    {
        $this->actingAsCustomer();

        $this->getJson('/api/v1/admin/shipments')->assertStatus(403);
    }
}
