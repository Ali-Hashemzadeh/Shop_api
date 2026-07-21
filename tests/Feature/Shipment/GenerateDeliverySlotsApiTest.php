<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Carbon\Carbon;
use Modules\Shipment\Domain\Models\DeliverySlot;

/**
 * POST /api/v1/admin/shipment/delivery-slots/generate — the manual trigger for the
 * nightly shipment:generate-delivery-slots command, for environments with no cron.
 */
class GenerateDeliverySlotsApiTest extends ShipmentTestCase
{
    private const URI = '/api/v1/admin/shipment/delivery-slots/generate';

    /** @test */
    public function it_generates_slots_for_the_requested_number_of_days(): void
    {
        $this->createWorkingPeriod(Carbon::today()->dayOfWeek, '09:00:00', '12:00:00');
        $this->actingAsOperator();

        $response = $this->postJson(self::URI, ['days' => 1]);

        $response->assertOk()->assertJson(['data' => ['created' => 2, 'days' => 1]]);
        $this->assertSame(2, DeliverySlot::where('delivery_date', Carbon::today()->toDateString())->count());
    }

    /** @test */
    public function it_falls_back_to_the_configured_generation_window_when_days_is_omitted(): void
    {
        config(['shipment.delivery.generation_days' => 1]);
        $this->createWorkingPeriod(Carbon::today()->dayOfWeek, '09:00:00', '12:00:00');
        $this->actingAsOperator();

        $this->postJson(self::URI)
            ->assertOk()
            ->assertJson(['data' => ['created' => 2, 'days' => 1]]);
    }

    /** @test */
    public function repeating_the_call_creates_nothing_and_preserves_operator_edits(): void
    {
        $this->createWorkingPeriod(Carbon::today()->dayOfWeek, '09:00:00', '12:00:00');
        $this->actingAsOperator();

        $this->postJson(self::URI, ['days' => 1])->assertOk();

        // An operator widens one session and closes it — a re-run must not undo either.
        $slot = DeliverySlot::orderBy('starts_at')->firstOrFail();
        $slot->update(['capacity' => 9, 'status' => 'closed']);

        $this->postJson(self::URI, ['days' => 1])
            ->assertOk()
            ->assertJson(['data' => ['created' => 0]]);

        $slot->refresh();
        $this->assertSame(9, $slot->capacity);
        $this->assertSame('closed', $slot->status);
    }

    /** @test */
    public function days_must_be_an_integer_within_the_supported_window(): void
    {
        $this->actingAsOperator();

        $this->postJson(self::URI, ['days' => 0])->assertStatus(422)->assertJsonValidationErrors('days');
        $this->postJson(self::URI, ['days' => 91])->assertStatus(422)->assertJsonValidationErrors('days');
        $this->postJson(self::URI, ['days' => 'soon'])->assertStatus(422)->assertJsonValidationErrors('days');
    }

    /** @test */
    public function it_accepts_a_whole_number_string_from_a_form_encoded_request(): void
    {
        $this->createWorkingPeriod(Carbon::today()->dayOfWeek, '09:00:00', '12:00:00');
        $this->actingAsOperator();

        $this->post(self::URI, ['days' => '1'])
            ->assertOk()
            ->assertJson(['data' => ['created' => 2, 'days' => 1]]);
    }

    /** @test */
    public function guests_are_rejected_with_401(): void
    {
        $this->postJson(self::URI, ['days' => 1])->assertStatus(401);
    }

    /** @test */
    public function a_user_without_the_slot_manage_permission_is_rejected_with_403(): void
    {
        $this->actingAsCustomer();

        $this->postJson(self::URI, ['days' => 1])->assertStatus(403);
        $this->assertSame(0, DeliverySlot::count());
    }
}
