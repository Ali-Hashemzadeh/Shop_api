<?php

declare(strict_types=1);

namespace Tests\Unit\Promotion;

use Modules\Promotion\Domain\DTOs\AutomaticDiscountContextDTO;
use Modules\Promotion\Domain\Enums\DiscountTargetType;
use Modules\Promotion\Domain\Enums\DiscountType;
use Modules\Promotion\Domain\Models\Discount;
use Modules\Promotion\Domain\Services\AutomaticDiscountResolver;
use Modules\Promotion\Domain\Services\DiscountCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Winner selection: which single rule applies, and why.
 *
 * The governing principle is that the customer gets the biggest saving, and
 * specificity only ever settles an exact tie.
 */
class AutomaticDiscountResolverTest extends TestCase
{
    private AutomaticDiscountResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new AutomaticDiscountResolver(new DiscountCalculator);
    }

    private function context(int $basePrice = 100_000_000): AutomaticDiscountContextDTO
    {
        return new AutomaticDiscountContextDTO(
            variantId: 91,
            productId: 15,
            categoryIds: [7, 3],
            brandId: 4,
            basePrice: $basePrice,
        );
    }

    /** @return array{discount: Discount, targetType: DiscountTargetType} */
    private function candidate(
        int $id,
        DiscountTargetType $targetType,
        ?int $bps = null,
        ?int $fixed = null,
        int $priority = 0,
    ): array {
        $discount = new Discount;
        $discount->id = $id;
        $discount->name = "Rule {$id}";
        $discount->priority = $priority;

        if ($bps !== null) {
            $discount->discount_type = DiscountType::PERCENTAGE;
            $discount->percentage_bps = $bps;
        } else {
            $discount->discount_type = DiscountType::FIXED_AMOUNT;
            $discount->fixed_amount = $fixed;
        }

        return ['discount' => $discount, 'targetType' => $targetType];
    }

    #[Test]
    public function the_largest_rial_reduction_wins(): void
    {
        // The spec's worked example: 20% product beats 10% category beats 15m fixed.
        $result = $this->resolver->resolve($this->context(), [
            $this->candidate(1, DiscountTargetType::PRODUCT, bps: 2000),      // 20,000,000
            $this->candidate(2, DiscountTargetType::CATEGORY, bps: 1000),     // 10,000,000
            $this->candidate(3, DiscountTargetType::VARIANT, fixed: 15_000_000),
        ]);

        $this->assertSame(1, $result->discountId);
        $this->assertSame(20_000_000, $result->discountAmount);
        $this->assertSame(80_000_000, $result->effectivePrice);
    }

    #[Test]
    public function a_product_rule_beats_a_variant_rule_when_it_saves_more(): void
    {
        // Specificity must NOT override customer savings: 30% product > 20% variant.
        $result = $this->resolver->resolve($this->context(), [
            $this->candidate(1, DiscountTargetType::VARIANT, bps: 2000),
            $this->candidate(2, DiscountTargetType::PRODUCT, bps: 3000),
        ]);

        $this->assertSame(2, $result->discountId);
        $this->assertSame(DiscountTargetType::PRODUCT, $result->matchedTargetType);
        $this->assertSame(30_000_000, $result->discountAmount);
    }

    #[Test]
    public function a_fixed_rule_beats_a_percentage_rule_when_it_saves_more(): void
    {
        $result = $this->resolver->resolve($this->context(), [
            $this->candidate(1, DiscountTargetType::CATEGORY, bps: 1000),      // 10,000,000
            $this->candidate(2, DiscountTargetType::BRAND, fixed: 25_000_000), // 25,000,000
        ]);

        $this->assertSame(2, $result->discountId);
        $this->assertSame(25_000_000, $result->discountAmount);
    }

    #[Test]
    public function an_exact_tie_is_broken_by_variant_specificity(): void
    {
        // Identical 20,000,000 reduction either way — the price is the same, but the
        // recorded winner must be deterministic.
        $result = $this->resolver->resolve($this->context(), [
            $this->candidate(1, DiscountTargetType::PRODUCT, bps: 2000),
            $this->candidate(2, DiscountTargetType::VARIANT, bps: 2000),
        ]);

        $this->assertSame(2, $result->discountId);
        $this->assertSame(DiscountTargetType::VARIANT, $result->matchedTargetType);
    }

    #[Test]
    public function tie_specificity_order_is_product_then_category_then_brand(): void
    {
        $productOverCategory = $this->resolver->resolve($this->context(), [
            $this->candidate(1, DiscountTargetType::CATEGORY, bps: 2000),
            $this->candidate(2, DiscountTargetType::PRODUCT, bps: 2000),
        ]);
        $this->assertSame(2, $productOverCategory->discountId);

        $categoryOverBrand = $this->resolver->resolve($this->context(), [
            $this->candidate(1, DiscountTargetType::BRAND, bps: 2000),
            $this->candidate(2, DiscountTargetType::CATEGORY, bps: 2000),
        ]);
        $this->assertSame(2, $categoryOverBrand->discountId);
    }

    #[Test]
    public function priority_breaks_a_tie_at_equal_specificity(): void
    {
        $result = $this->resolver->resolve($this->context(), [
            $this->candidate(1, DiscountTargetType::PRODUCT, bps: 2000, priority: 1),
            $this->candidate(2, DiscountTargetType::PRODUCT, bps: 2000, priority: 9),
        ]);

        $this->assertSame(2, $result->discountId);
    }

    #[Test]
    public function the_lowest_id_is_the_final_tie_break(): void
    {
        // Everything else identical — the oldest rule wins, forever and repeatably.
        $result = $this->resolver->resolve($this->context(), [
            $this->candidate(7, DiscountTargetType::PRODUCT, bps: 2000),
            $this->candidate(3, DiscountTargetType::PRODUCT, bps: 2000),
            $this->candidate(9, DiscountTargetType::PRODUCT, bps: 2000),
        ]);

        $this->assertSame(3, $result->discountId);
    }

    #[Test]
    public function resolution_is_independent_of_candidate_order(): void
    {
        $candidates = [
            $this->candidate(1, DiscountTargetType::CATEGORY, bps: 1000),
            $this->candidate(2, DiscountTargetType::PRODUCT, bps: 2000),
            $this->candidate(3, DiscountTargetType::VARIANT, fixed: 15_000_000),
        ];

        $forward = $this->resolver->resolve($this->context(), $candidates);
        $reversed = $this->resolver->resolve($this->context(), array_reverse($candidates));

        $this->assertSame($forward->discountId, $reversed->discountId);
        $this->assertSame($forward->discountAmount, $reversed->discountAmount);
    }

    #[Test]
    public function a_rule_that_saves_nothing_is_not_a_winner(): void
    {
        // A 0-rial reduction must not produce a "discount" badge on the storefront.
        $this->assertNull($this->resolver->resolve($this->context(), [
            $this->candidate(1, DiscountTargetType::PRODUCT, fixed: 0),
        ]));
    }

    #[Test]
    public function no_candidates_means_no_discount(): void
    {
        $this->assertNull($this->resolver->resolve($this->context(), []));
    }

    #[Test]
    public function the_effective_price_can_never_go_negative(): void
    {
        $result = $this->resolver->resolve($this->context(1_000_000), [
            $this->candidate(1, DiscountTargetType::PRODUCT, fixed: 9_999_999_9),
        ]);

        $this->assertSame(0, $result->effectivePrice);
        $this->assertGreaterThanOrEqual(0, $result->effectivePrice);
    }

    #[Test]
    public function the_snapshot_explains_the_winner_without_a_live_lookup(): void
    {
        $result = $this->resolver->resolve($this->context(), [
            $this->candidate(1, DiscountTargetType::PRODUCT, bps: 2000),
        ]);

        $this->assertSame([
            'discount_id' => 1,
            'name' => 'Rule 1',
            'type' => 'percentage',
            'percentage_bps' => 2000,
            'fixed_amount' => null,
            'matched_target_type' => 'product',
            'base_price' => 100_000_000,
            'discount_amount' => 20_000_000,
            'effective_price' => 80_000_000,
        ], $result->toSnapshot());
    }
}
