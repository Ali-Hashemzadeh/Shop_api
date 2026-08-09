<?php

declare(strict_types=1);

namespace Tests\Unit\Promotion;

use Modules\Promotion\Domain\Enums\DiscountType;
use Modules\Promotion\Domain\Models\Discount;
use Modules\Promotion\Domain\Services\DiscountCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The integer arithmetic underpinning every promotional price.
 *
 * Pure unit tests — no database — because this is the one place a rounding or
 * float error would silently mis-charge every order in the system.
 */
class DiscountCalculatorTest extends TestCase
{
    private DiscountCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new DiscountCalculator;
    }

    private function percentage(int $bps, ?int $cap = null): Discount
    {
        $discount = new Discount;
        $discount->discount_type = DiscountType::PERCENTAGE;
        $discount->percentage_bps = $bps;
        $discount->max_discount_amount = $cap;

        return $discount;
    }

    private function fixed(int $amount, ?int $cap = null): Discount
    {
        $discount = new Discount;
        $discount->discount_type = DiscountType::FIXED_AMOUNT;
        $discount->fixed_amount = $amount;
        $discount->max_discount_amount = $cap;

        return $discount;
    }

    #[Test]
    public function it_computes_whole_percentages_in_basis_points(): void
    {
        // 2000 bps == 20% of 100,000,000 == 20,000,000
        $this->assertSame(20_000_000, $this->calculator->reductionFor($this->percentage(2000), 100_000_000));
        $this->assertSame(10_000_000, $this->calculator->reductionFor($this->percentage(1000), 100_000_000));
    }

    #[Test]
    public function it_supports_fractional_percentages_without_floats(): void
    {
        // 12.5% is representable exactly as 1250 bps — the reason bps exist at all.
        $this->assertSame(12_500_000, $this->calculator->reductionFor($this->percentage(1250), 100_000_000));
    }

    #[Test]
    public function one_hundred_percent_is_ten_thousand_bps(): void
    {
        $this->assertSame(100_000_000, $this->calculator->reductionFor($this->percentage(10000), 100_000_000));
        $this->assertSame(0, $this->calculator->effectivePriceFor($this->percentage(10000), 100_000_000));
    }

    #[Test]
    public function percentages_floor_rather_than_round(): void
    {
        // 33% of 1,000 rials is 330 exactly; 33% of 1,001 is 330.33 → floors to 330.
        // Flooring is the documented strategy: the discount never rounds up.
        $this->assertSame(330, $this->calculator->reductionFor($this->percentage(3300), 1000));
        $this->assertSame(330, $this->calculator->reductionFor($this->percentage(3300), 1001));
    }

    #[Test]
    public function it_multiplies_before_dividing_so_small_amounts_are_not_lost(): void
    {
        // Dividing first (1 / 10000) would truncate to zero and then multiply to
        // zero. Multiplying first gives the honest floor.
        $this->assertSame(0, $this->calculator->reductionFor($this->percentage(1000), 5));
        $this->assertSame(1, $this->calculator->reductionFor($this->percentage(1000), 10));
        $this->assertSame(9, $this->calculator->reductionFor($this->percentage(1000), 99));
    }

    #[Test]
    public function every_result_is_a_native_integer(): void
    {
        $result = $this->calculator->reductionFor($this->percentage(3333), 100_000_001);

        $this->assertIsInt($result);
        $this->assertSame($result, (int) $result);
    }

    #[Test]
    public function it_returns_the_configured_fixed_amount(): void
    {
        $this->assertSame(15_000_000, $this->calculator->reductionFor($this->fixed(15_000_000), 100_000_000));
    }

    #[Test]
    public function a_percentage_cap_limits_the_reduction(): void
    {
        // 20% of 100,000,000 would be 20,000,000, but the rule caps at 5,000,000.
        $this->assertSame(5_000_000, $this->calculator->reductionFor($this->percentage(2000, 5_000_000), 100_000_000));
    }

    #[Test]
    public function a_cap_above_the_computed_amount_does_nothing(): void
    {
        $this->assertSame(20_000_000, $this->calculator->reductionFor($this->percentage(2000, 50_000_000), 100_000_000));
    }

    #[Test]
    public function a_fixed_amount_larger_than_the_price_cannot_go_negative(): void
    {
        // Clamped to the amount, so the price lands on zero rather than below it.
        $this->assertSame(1_000_000, $this->calculator->reductionFor($this->fixed(5_000_000), 1_000_000));
        $this->assertSame(0, $this->calculator->effectivePriceFor($this->fixed(5_000_000), 1_000_000));
    }

    #[Test]
    public function a_zero_or_negative_base_yields_no_reduction(): void
    {
        $this->assertSame(0, $this->calculator->reductionFor($this->percentage(2000), 0));
        $this->assertSame(0, $this->calculator->reductionFor($this->fixed(5_000), 0));
    }

    #[Test]
    public function effective_price_is_base_minus_reduction(): void
    {
        $this->assertSame(80_000_000, $this->calculator->effectivePriceFor($this->percentage(2000), 100_000_000));
        $this->assertSame(85_000_000, $this->calculator->effectivePriceFor($this->fixed(15_000_000), 100_000_000));
    }
}
