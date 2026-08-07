<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Shipment\Domain\Enums\ReservationStatus;
use Modules\Shipment\Domain\Models\DeliveryScheduleException;
use Modules\Shipment\Domain\Models\DeliverySlotReservation;

class DeliverySlotSortingTest extends ShipmentTestCase
{
    private int $addressId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-07 08:00:00');
        $user = $this->actingAsCustomer();
        $this->addressId = $this->createAddress($user->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return list<int> */
    private function slotIds(string $query = ''): array
    {
        $suffix = $query === '' ? '' : '&'.$query;
        $response = $this->getJson("/api/v1/shipment/delivery-slots?address_id={$this->addressId}{$suffix}")
            ->assertOk();

        return collect($response->json('data'))
            ->flatMap(static fn (array $group) => collect($group['slots'])->pluck('id'))
            ->values()
            ->all();
    }

    /** @test */
    public function default_and_explicit_date_sort_use_chronological_order_in_both_directions(): void
    {
        $laterDay = $this->createSlot('2026-08-09', '11:00:00');
        $earlyDayLateTime = $this->createSlot('2026-08-08', '14:00:00');
        $earlyDayEarlyTime = $this->createSlot('2026-08-08', '10:00:00');

        $ascending = [$earlyDayEarlyTime->id, $earlyDayLateTime->id, $laterDay->id];

        $this->assertSame($ascending, $this->slotIds());
        $this->assertSame($ascending, $this->slotIds('direction=desc'));
        $this->assertSame($ascending, $this->slotIds('sort=date&direction=asc'));
        $this->assertSame(array_reverse($ascending), $this->slotIds('sort=date&direction=desc'));
    }

    /** @test */
    public function starts_at_capacity_and_created_at_support_both_directions(): void
    {
        $middle = $this->createSlot('2026-08-09', '12:00:00', capacity: 5);
        $late = $this->createSlot('2026-08-09', '16:00:00', capacity: 2);
        $early = $this->createSlot('2026-08-09', '09:00:00', capacity: 8);

        DB::table('delivery_slots')->where('id', $middle->id)->update(['created_at' => '2026-08-07 10:00:00']);
        DB::table('delivery_slots')->where('id', $late->id)->update(['created_at' => '2026-08-07 11:00:00']);
        DB::table('delivery_slots')->where('id', $early->id)->update(['created_at' => '2026-08-07 09:00:00']);

        $this->assertSame([$early->id, $middle->id, $late->id], $this->slotIds('sort=starts_at&direction=asc'));
        $this->assertSame([$late->id, $middle->id, $early->id], $this->slotIds('sort=starts_at&direction=desc'));
        $this->assertSame([$late->id, $middle->id, $early->id], $this->slotIds('sort=capacity&direction=asc'));
        $this->assertSame([$early->id, $middle->id, $late->id], $this->slotIds('sort=capacity&direction=desc'));
        $this->assertSame([$early->id, $middle->id, $late->id], $this->slotIds('sort=created_at&direction=asc'));
        $this->assertSame([$late->id, $middle->id, $early->id], $this->slotIds('sort=created_at&direction=desc'));
    }

    /** @test */
    public function remaining_capacity_sort_reuses_active_reservation_calculation(): void
    {
        $least = $this->createSlot('2026-08-09', '09:00:00', capacity: 5, adminReserved: 2);
        $middle = $this->createSlot('2026-08-09', '12:00:00', capacity: 6, adminReserved: 1);
        $most = $this->createSlot('2026-08-09', '15:00:00', capacity: 8);

        DeliverySlotReservation::create([
            'delivery_slot_id' => $least->id,
            'order_id' => 9001,
            'user_id' => 1,
            'status' => ReservationStatus::Held->value,
        ]);
        DeliverySlotReservation::create([
            'delivery_slot_id' => $middle->id,
            'order_id' => 9002,
            'user_id' => 1,
            'status' => ReservationStatus::Confirmed->value,
        ]);

        $this->assertSame([$least->id, $middle->id, $most->id], $this->slotIds('sort=remaining_capacity&direction=asc'));
        $this->assertSame([$most->id, $middle->id, $least->id], $this->slotIds('sort=remaining_capacity&direction=desc'));
    }

    /** @test */
    public function invalid_sort_and_direction_are_rejected(): void
    {
        $base = "/api/v1/shipment/delivery-slots?address_id={$this->addressId}";

        $this->getJson($base.'&sort=DROP_TABLE')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');

        $this->getJson($base.'&direction=random')
            ->assertStatus(422)
            ->assertJsonValidationErrors('direction');
    }

    /** @test */
    public function sorting_does_not_change_existing_availability_rules(): void
    {
        $open = $this->createSlot('2026-08-09', '10:00:00');
        $closed = $this->createSlot('2026-08-09', '12:00:00', status: 'closed');
        $full = $this->createSlot('2026-08-09', '14:00:00', capacity: 1, adminReserved: 1);
        $exception = $this->createSlot('2026-08-10', '10:00:00');
        DeliveryScheduleException::create(['date' => '2026-08-10', 'type' => 'closed']);

        $response = $this->getJson("/api/v1/shipment/delivery-slots?address_id={$this->addressId}&sort=date&direction=desc")
            ->assertOk();
        $slots = collect($response->json('data'))->flatMap(fn (array $group) => $group['slots'])->keyBy('id');

        $this->assertTrue($slots[$open->id]['available']);
        $this->assertFalse($slots[$closed->id]['available']);
        $this->assertFalse($slots[$full->id]['available']);
        $this->assertFalse($slots[$exception->id]['available']);
    }
}
