<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Carbon\Carbon;
use Modules\Shipment\Domain\Models\DeliveryWorkingPeriod;

class DeliveryWorkingPeriodApiTest extends ShipmentTestCase
{
    public function test_admin_can_create_list_update_and_delete_working_periods(): void
    {
        $this->actingAsAdmin();

        $created = $this->postJson('/api/v1/admin/shipment/delivery-working-periods', [
            'weekday' => 6,
            'starts_at' => '09:00',
            'ends_at' => '18:00',
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('weekday', 6)
            ->assertJsonPath('starts_at', '09:00')
            ->assertJsonPath('ends_at', '18:00');

        $id = $created->json('id');

        $this->getJson('/api/v1/admin/shipment/delivery-working-periods')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->patchJson("/api/v1/admin/shipment/delivery-working-periods/{$id}", [
            'starts_at' => '10:00',
            'ends_at' => '17:00',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('starts_at', '10:00')
            ->assertJsonPath('is_active', false);

        $this->deleteJson("/api/v1/admin/shipment/delivery-working-periods/{$id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('delivery_working_periods', ['id' => $id]);
    }

    public function test_validation_rejects_invalid_weekday_times_and_overlapping_periods(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/shipment/delivery-working-periods', [
            'weekday' => 7,
            'starts_at' => '18:00',
            'ends_at' => '09:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['weekday', 'ends_at']);

        $this->postJson('/api/v1/admin/shipment/delivery-working-periods', [
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '13:00',
        ])->assertCreated();

        $this->postJson('/api/v1/admin/shipment/delivery-working-periods', [
            'weekday' => 1,
            'starts_at' => '12:00',
            'ends_at' => '16:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->postJson('/api/v1/admin/shipment/delivery-working-periods', [
            'weekday' => 1,
            'starts_at' => '13:00',
            'ends_at' => '16:00',
        ])->assertCreated();
    }

    public function test_update_validates_the_effective_combined_time_range(): void
    {
        $this->actingAsAdmin();
        $period = DeliveryWorkingPeriod::create([
            'weekday' => 2,
            'starts_at' => '09:00',
            'ends_at' => '17:00',
            'is_active' => true,
        ]);

        $this->patchJson("/api/v1/admin/shipment/delivery-working-periods/{$period->id}", [
            'starts_at' => '18:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');

        $this->assertDatabaseHas('delivery_working_periods', [
            'id' => $period->id,
            'starts_at' => '09:00',
            'ends_at' => '17:00',
        ]);
    }

    public function test_existing_slot_permissions_protect_working_period_endpoints(): void
    {
        $this->actingAsCustomer();

        $this->getJson('/api/v1/admin/shipment/delivery-working-periods')->assertForbidden();
        $this->postJson('/api/v1/admin/shipment/delivery-working-periods', [
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '17:00',
        ])->assertForbidden();
    }

    public function test_created_period_drives_idempotent_slot_generation(): void
    {
        $this->actingAsAdmin();
        $weekday = Carbon::today()->dayOfWeek;

        $this->postJson('/api/v1/admin/shipment/delivery-working-periods', [
            'weekday' => $weekday,
            'starts_at' => '09:00',
            'ends_at' => '12:00',
        ])->assertCreated();

        $this->artisan('shipment:generate-delivery-slots', ['--days' => 1])
            ->assertSuccessful();
        $this->assertDatabaseCount('delivery_slots', 2);

        $this->artisan('shipment:generate-delivery-slots', ['--days' => 1])
            ->assertSuccessful();
        $this->assertDatabaseCount('delivery_slots', 2);
    }
}
