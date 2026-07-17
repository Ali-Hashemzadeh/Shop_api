<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Carbon\Carbon;
use Modules\Shipment\Application\Actions\GenerateDeliverySlotsAction;
use Modules\Shipment\Application\Services\DeliverySlotAvailabilityService;
use Modules\Shipment\Domain\Enums\ReservationStatus;
use Modules\Shipment\Domain\Models\DeliveryScheduleException;
use Modules\Shipment\Domain\Models\DeliverySlot;
use Modules\Shipment\Domain\Models\DeliverySlotReservation;

class ShipmentSlotTest extends ShipmentTestCase
{
    private function generate(int $days = 1): int
    {
        return app(GenerateDeliverySlotsAction::class)->handle($days);
    }

    private function todayWeekday(): int
    {
        return Carbon::today()->dayOfWeek;
    }

    private function reserve(DeliverySlot $slot, string $status, int $orderId): void
    {
        DeliverySlotReservation::create([
            'delivery_slot_id' => $slot->id,
            'order_id' => $orderId,
            'user_id' => 1,
            'status' => $status,
        ]);
    }

    // ── Generation ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_generates_ninety_minute_slots_from_a_working_period(): void
    {
        $this->createWorkingPeriod($this->todayWeekday(), '09:00:00', '12:00:00');

        $this->generate(1);

        $slots = DeliverySlot::where('delivery_date', Carbon::today()->toDateString())->orderBy('starts_at')->get();
        $this->assertCount(2, $slots);
        $this->assertSame('09:00:00', $slots[0]->starts_at);
        $this->assertSame('10:30:00', $slots[0]->ends_at);
        $this->assertSame('12:00:00', $slots[1]->ends_at);
    }

    /** @test */
    public function it_handles_multiple_working_periods_and_a_valid_final_short_slot(): void
    {
        $weekday = $this->todayWeekday();
        $this->createWorkingPeriod($weekday, '09:00:00', '12:00:00'); // 2 slots
        $this->createWorkingPeriod($weekday, '15:00:00', '22:00:00'); // 5 slots incl. 21:00-22:00 (60m)

        $this->generate(1);

        $slots = DeliverySlot::where('delivery_date', Carbon::today()->toDateString())->get();
        $this->assertCount(7, $slots);
        $this->assertTrue($slots->contains(fn (DeliverySlot $s) => $s->starts_at === '21:00:00' && $s->ends_at === '22:00:00'));
    }

    /** @test */
    public function it_omits_a_final_slot_shorter_than_the_minimum(): void
    {
        // 09:00-09:45 → 45-minute remainder, below the 60-minute minimum.
        $this->createWorkingPeriod($this->todayWeekday(), '09:00:00', '09:45:00');

        $this->generate(1);

        $this->assertSame(0, DeliverySlot::where('delivery_date', Carbon::today()->toDateString())->count());
    }

    /** @test */
    public function it_skips_closed_dates_and_applies_custom_hours(): void
    {
        $weekday = $this->todayWeekday();
        $this->createWorkingPeriod($weekday, '09:00:00', '12:00:00');

        $today = Carbon::today()->toDateString();
        $tomorrow = Carbon::today()->addDay()->toDateString();

        DeliveryScheduleException::create(['date' => $today, 'type' => 'closed']);
        DeliveryScheduleException::create(['date' => $tomorrow, 'type' => 'custom_hours', 'starts_at' => '18:00:00', 'ends_at' => '19:30:00']);

        $this->generate(2);

        $this->assertSame(0, DeliverySlot::where('delivery_date', $today)->count());
        // Tomorrow uses custom hours regardless of the weekday template.
        $tomorrowSlots = DeliverySlot::where('delivery_date', $tomorrow)->get();
        $this->assertCount(1, $tomorrowSlots);
        $this->assertSame('18:00:00', $tomorrowSlots[0]->starts_at);
    }

    /** @test */
    public function generation_is_idempotent_and_preserves_operator_changes(): void
    {
        $this->createWorkingPeriod($this->todayWeekday(), '09:00:00', '12:00:00');

        $this->generate(1);
        $slot = DeliverySlot::where('delivery_date', Carbon::today()->toDateString())->first();
        $slot->update(['capacity' => 99, 'status' => 'closed']);

        // Second run creates nothing new and never overwrites/reopens.
        $this->generate(1);

        $this->assertSame(2, DeliverySlot::where('delivery_date', Carbon::today()->toDateString())->count());
        $slot->refresh();
        $this->assertSame(99, $slot->capacity);
        $this->assertSame('closed', $slot->status);
    }

    // ── Availability / capacity ────────────────────────────────────────────────

    /** @test */
    public function remaining_capacity_subtracts_admin_reserved_and_active_reservations(): void
    {
        $service = app(DeliverySlotAvailabilityService::class);
        $slot = $this->createBookableSlot(capacity: 3, adminReserved: 1);

        $this->reserve($slot, ReservationStatus::Held->value, 101);
        $this->assertSame(1, $service->remainingCapacity($slot));

        $this->reserve($slot, ReservationStatus::Confirmed->value, 102);
        $this->assertSame(0, $service->remainingCapacity($slot));
        $this->assertFalse($service->isSelectable($slot));
    }

    /** @test */
    public function released_and_expired_reservations_do_not_consume_capacity(): void
    {
        $service = app(DeliverySlotAvailabilityService::class);
        $slot = $this->createBookableSlot(capacity: 1);

        $this->reserve($slot, ReservationStatus::Released->value, 201);
        $this->reserve($slot, ReservationStatus::Expired->value, 202);
        $this->reserve($slot, ReservationStatus::Cancelled->value, 203);
        $this->reserve($slot, ReservationStatus::Completed->value, 204);

        $this->assertSame(1, $service->remainingCapacity($slot));
        $this->assertTrue($service->isSelectable($slot));
    }

    /** @test */
    public function past_lead_time_horizon_and_closed_slots_are_unavailable(): void
    {
        $service = app(DeliverySlotAvailabilityService::class);

        $past = $this->createSlot(Carbon::today()->subDay()->toDateString());
        $this->assertFalse($service->isSelectable($past));

        $beyondHorizon = $this->createSlot(Carbon::today()->addDays(30)->toDateString());
        $this->assertFalse($service->isSelectable($beyondHorizon));

        $closed = $this->createSlot(Carbon::today()->addDays(2)->toDateString(), status: 'closed');
        $this->assertFalse($service->isSelectable($closed));

        // Too soon (under the 60-minute lead time).
        $soon = $this->createSlot(
            Carbon::today()->toDateString(),
            Carbon::now()->addMinutes(10)->format('H:i:s'),
            Carbon::now()->addMinutes(100)->format('H:i:s'),
        );
        $this->assertFalse($service->isSelectable($soon));
    }
}
