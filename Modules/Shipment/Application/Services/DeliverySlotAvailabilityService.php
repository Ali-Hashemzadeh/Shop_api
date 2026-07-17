<?php

declare(strict_types=1);

namespace Modules\Shipment\Application\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Shipment\Domain\DTOs\DeliverySlotDTO;
use Modules\Shipment\Domain\Enums\DeliverySlotStatus;
use Modules\Shipment\Domain\Enums\ReservationStatus;
use Modules\Shipment\Domain\Models\DeliverySlot;

class DeliverySlotAvailabilityService
{
    /**
     * Count reservations that currently consume bookable capacity (held/confirmed).
     */
    public function activeReservationCount(int $slotId): int
    {
        return DB::table('delivery_slot_reservations')
            ->where('delivery_slot_id', $slotId)
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->count();
    }

    /**
     * remaining = capacity − admin_reserved_capacity − active(held + confirmed).
     * The reservation table is the single source of truth for consumed capacity.
     */
    public function remainingCapacity(DeliverySlot $slot, ?int $activeReservations = null): int
    {
        $active = $activeReservations ?? $this->activeReservationCount($slot->id);

        return $slot->capacity - $slot->admin_reserved_capacity - $active;
    }

    /**
     * Whether a slot may be selected at checkout right now.
     */
    public function isSelectable(DeliverySlot $slot, ?CarbonInterface $now = null): bool
    {
        $now ??= Carbon::now();

        if ($slot->status !== DeliverySlotStatus::Open->value) {
            return false;
        }

        if ($this->isDateClosedByException($slot->dateString())) {
            return false;
        }

        $start = $this->slotStart($slot);
        $leadCutoff = $now->copy()->addMinutes((int) config('shipment.delivery.minimum_lead_minutes', 60));

        if ($start->lte($leadCutoff)) {
            return false;
        }

        $horizonEnd = $now->copy()->startOfDay()->addDays((int) config('shipment.delivery.booking_horizon_days', 14))->endOfDay();

        if ($start->gt($horizonEnd)) {
            return false;
        }

        return $this->remainingCapacity($slot) > 0;
    }

    /**
     * Bookable sessions grouped by date within [from, until]. Full/closed slots are
     * returned with available=false so the frontend can render a disabled state.
     *
     * @return array<int, array{date: string, slots: DeliverySlotDTO[]}>
     */
    public function listGrouped(CarbonInterface $from, CarbonInterface $until, ?CarbonInterface $now = null): array
    {
        $now ??= Carbon::now();

        $slots = DeliverySlot::query()
            ->whereBetween('delivery_date', [$from->toDateString(), $until->toDateString()])
            ->orderBy('delivery_date')
            ->orderBy('starts_at')
            ->get();

        $grouped = [];

        foreach ($slots as $slot) {
            $remaining = $this->remainingCapacity($slot);
            $available = $this->isSelectable($slot, $now);
            $dto = DeliverySlotDTO::fromModel($slot, max($remaining, 0), $available);

            $date = $slot->dateString();
            $grouped[$date] ??= [];
            $grouped[$date][] = $dto;
        }

        return array_map(
            static fn (string $date, array $slots): array => ['date' => $date, 'slots' => $slots],
            array_keys($grouped),
            $grouped,
        );
    }

    public function isDateClosedByException(string $date): bool
    {
        return DB::table('delivery_schedule_exceptions')
            ->whereDate('date', $date)
            ->where('type', 'closed')
            ->exists();
    }

    public function slotStart(DeliverySlot $slot): Carbon
    {
        return Carbon::parse($slot->dateString().' '.substr((string) $slot->starts_at, 0, 8));
    }
}
