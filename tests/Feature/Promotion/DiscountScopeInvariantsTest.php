<?php

declare(strict_types=1);

namespace Tests\Feature\Promotion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Promotion\Domain\Enums\DiscountTargetType;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two legal discount shapes, enforced at the admin write boundary.
 *
 *   automatic ⇒ scope=targeted, at least one target, no coupon-only fields
 *   coupon    ⇒ scope=all,      zero targets
 *
 * These are the invariants that keep a coupon rule from silently discounting the
 * storefront, and an automatic rule from becoming an unbounded store-wide sale.
 */
class DiscountScopeInvariantsTest extends PromotionTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedCatalogPermissions();
        $this->seedPromotionPermissions();
        $this->actingAsAdmin();
    }

    /** POST a valid automatic discount, with per-test overrides. */
    private function createDiscount(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/admin/promotions/discounts', array_merge([
            'name' => 'Rule',
            'trigger_type' => 'automatic',
            'scope' => 'targeted',
            'discount_type' => 'percentage',
            'percentage_bps' => 1000,
            'targets' => [['target_type' => 'product', 'target_id' => 1]],
        ], $overrides));
    }

    // ── Automatic ─────────────────────────────────────────────────────────────

    #[Test]
    public function an_automatic_discount_cannot_use_scope_all(): void
    {
        $this->createDiscount(['scope' => 'all'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');
    }

    #[Test]
    public function an_automatic_discount_requires_at_least_one_target(): void
    {
        $this->createDiscount(['targets' => []])->assertStatus(422)->assertJsonValidationErrors('targets');

        $this->postJson('/api/v1/admin/promotions/discounts', [
            'name' => 'Rule',
            'trigger_type' => 'automatic',
            'scope' => 'targeted',
            'discount_type' => 'percentage',
            'percentage_bps' => 1000,
        ])->assertStatus(422)->assertJsonValidationErrors('targets');
    }

    #[Test]
    public function an_automatic_discount_rejects_the_coupon_only_minimum_subtotal(): void
    {
        $this->createDiscount(['min_subtotal' => 5_000_000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('min_subtotal');
    }

    #[Test]
    public function a_valid_automatic_discount_is_created_with_its_targets(): void
    {
        $this->createDiscount([
            'targets' => [
                ['target_type' => 'product', 'target_id' => 15],
                ['target_type' => 'variant', 'target_id' => 91],
                ['target_type' => 'category', 'target_id' => 7],
                ['target_type' => 'brand', 'target_id' => 4],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('trigger_type', 'automatic')
            ->assertJsonPath('scope', 'targeted')
            ->assertJsonCount(4, 'targets');
    }

    #[Test]
    public function catalog_target_ids_are_not_verified_to_exist(): void
    {
        // Deliberate: verifying them would mean Promotion calling Catalog, and
        // Catalog already calls Promotion for pricing. A stale target is inert.
        $this->createDiscount(['targets' => [['target_type' => 'product', 'target_id' => 999999]]])
            ->assertCreated()
            ->assertJsonPath('targets.0.target_id', 999999);
    }

    #[Test]
    public function duplicate_targets_are_collapsed(): void
    {
        $this->createDiscount([
            'targets' => [
                ['target_type' => 'product', 'target_id' => 15],
                ['target_type' => 'product', 'target_id' => 15],
            ],
        ])
            ->assertCreated()
            ->assertJsonCount(1, 'targets');
    }

    #[Test]
    public function an_unknown_target_type_is_rejected(): void
    {
        // `all` is expressed by scope, never by a magic target row.
        $this->createDiscount(['targets' => [['target_type' => 'all', 'target_id' => 1]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('targets.0.target_type');
    }

    // ── Coupon ────────────────────────────────────────────────────────────────

    #[Test]
    public function a_coupon_discount_must_use_scope_all(): void
    {
        $this->createDiscount(['trigger_type' => 'coupon', 'scope' => 'targeted', 'targets' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');
    }

    #[Test]
    public function a_coupon_discount_cannot_have_targets(): void
    {
        $this->createDiscount([
            'trigger_type' => 'coupon',
            'scope' => 'all',
            'targets' => [['target_type' => 'product', 'target_id' => 15]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('targets');
    }

    #[Test]
    public function a_valid_coupon_discount_is_created_with_no_targets(): void
    {
        $this->postJson('/api/v1/admin/promotions/discounts', [
            'name' => 'Welcome',
            'trigger_type' => 'coupon',
            'scope' => 'all',
            'discount_type' => 'percentage',
            'percentage_bps' => 1000,
            'min_subtotal' => 5_000_000,
            'max_discount_amount' => 2_000_000,
        ])
            ->assertCreated()
            ->assertJsonPath('scope', 'all')
            ->assertJsonPath('min_subtotal', 5_000_000)
            ->assertJsonCount(0, 'targets');
    }

    #[Test]
    public function an_update_cannot_bolt_targets_onto_an_existing_coupon_rule(): void
    {
        $coupon = $this->coupon('SUMMER10', bps: 1000);

        // The request body alone carries no trigger_type — the stored row supplies it.
        $this->patchJson("/api/v1/admin/promotions/discounts/{$coupon->discount_id}", [
            'targets' => [['target_type' => 'product', 'target_id' => 15]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('targets');
    }

    #[Test]
    public function an_update_cannot_empty_an_automatic_rules_targets(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $discount = $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 1000);

        $this->patchJson("/api/v1/admin/promotions/discounts/{$discount->id}", ['targets' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('targets');
    }

    #[Test]
    public function a_partial_update_that_omits_targets_leaves_them_untouched(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $discount = $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 1000);

        $this->patchJson("/api/v1/admin/promotions/discounts/{$discount->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('name', 'Renamed')
            ->assertJsonCount(1, 'targets');
    }

    // ── Value shape ───────────────────────────────────────────────────────────

    #[Test]
    public function a_percentage_rule_requires_bps_and_forbids_a_fixed_amount(): void
    {
        $this->createDiscount(['percentage_bps' => null])->assertStatus(422)->assertJsonValidationErrors('percentage_bps');
        $this->createDiscount(['fixed_amount' => 5000])->assertStatus(422)->assertJsonValidationErrors('fixed_amount');
    }

    #[Test]
    public function a_fixed_rule_requires_an_amount_and_forbids_bps(): void
    {
        $this->createDiscount(['discount_type' => 'fixed_amount', 'percentage_bps' => null])
            ->assertStatus(422)->assertJsonValidationErrors('fixed_amount');

        $this->createDiscount(['discount_type' => 'fixed_amount', 'fixed_amount' => 5000, 'percentage_bps' => 1000])
            ->assertStatus(422)->assertJsonValidationErrors('percentage_bps');
    }

    #[Test]
    public function percentage_bps_is_bounded_to_one_hundred_percent(): void
    {
        // 10001 bps would be >100% — i.e. paying the customer to take the goods.
        $this->createDiscount(['percentage_bps' => 10001])->assertStatus(422)->assertJsonValidationErrors('percentage_bps');
        $this->createDiscount(['percentage_bps' => 0])->assertStatus(422)->assertJsonValidationErrors('percentage_bps');
        $this->createDiscount(['percentage_bps' => 10000])->assertCreated();
    }

    #[Test]
    public function an_end_date_before_the_start_date_is_rejected(): void
    {
        $this->createDiscount([
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'ends_at' => now()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors('ends_at');
    }

    #[Test]
    public function switching_from_percentage_to_fixed_clears_the_unused_value(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $discount = $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 1000);

        $this->patchJson("/api/v1/admin/promotions/discounts/{$discount->id}", [
            'discount_type' => 'fixed_amount',
            'fixed_amount' => 5_000_000,
        ])
            ->assertOk()
            ->assertJsonPath('discount_type', 'fixed_amount')
            ->assertJsonPath('fixed_amount', 5_000_000)
            // The stale percentage must not survive to be resurrected by a later edit.
            ->assertJsonPath('percentage_bps', null);
    }

    // ── Coupon codes ──────────────────────────────────────────────────────────

    #[Test]
    public function coupon_codes_are_normalized_and_unique(): void
    {
        $coupon = $this->coupon('SUMMER10', bps: 1000);

        $this->postJson('/api/v1/admin/promotions/coupons', [
            'discount_id' => $coupon->discount_id,
            'code' => '  summer10  ',
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/admin/promotions/coupons', [
            'discount_id' => $coupon->discount_id,
            'code' => '  winter20  ',
        ])->assertCreated()->assertJsonPath('code', 'WINTER20');
    }

    #[Test]
    public function coupon_codes_reject_characters_outside_the_accepted_alphabet(): void
    {
        $coupon = $this->coupon('SUMMER10', bps: 1000);

        foreach (['SUMMER 10', 'SUMMER@10', 'SUMMER%'] as $bad) {
            $this->postJson('/api/v1/admin/promotions/coupons', [
                'discount_id' => $coupon->discount_id,
                'code' => $bad,
            ])->assertStatus(422)->assertJsonValidationErrors('code');
        }

        $this->postJson('/api/v1/admin/promotions/coupons', [
            'discount_id' => $coupon->discount_id,
            'code' => 'NOWRUZ_1404-VIP',
        ])->assertCreated();
    }

    #[Test]
    public function a_coupon_cannot_be_backed_by_an_automatic_discount(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $automatic = $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 1000);

        $this->postJson('/api/v1/admin/promotions/coupons', [
            'discount_id' => $automatic->id,
            'code' => 'BADCODE',
        ])->assertStatus(422)->assertJsonValidationErrors('discount_id');
    }
}
