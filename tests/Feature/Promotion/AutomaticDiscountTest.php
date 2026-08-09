<?php

declare(strict_types=1);

namespace Tests\Feature\Promotion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Promotion\Domain\Enums\DiscountTargetType;
use PHPUnit\Framework\Attributes\Test;

/**
 * Automatic discounts end-to-end: Catalog asks Promotion, Promotion answers from
 * its own tables, and the storefront shows one winning price.
 */
class AutomaticDiscountTest extends PromotionTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedCatalogPermissions();
    }

    private function catalog(): CatalogManagerInterface
    {
        return app(CatalogManagerInterface::class);
    }

    // ── Targeting ─────────────────────────────────────────────────────────────

    #[Test]
    public function a_product_target_discounts_every_variant_of_that_product(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000);

        $dto = $this->catalog()->findProduct($product->uuid);

        $this->assertSame(100_000_000, $dto->variants[0]->basePrice);
        $this->assertSame(80_000_000, $dto->variants[0]->effectivePrice());
        $this->assertSame(20_000_000, $dto->variants[0]->automaticDiscount->discountAmount);
    }

    #[Test]
    public function a_variant_target_discounts_only_that_variant(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $variant = $this->defaultVariant($product);
        $this->automaticDiscount([[DiscountTargetType::VARIANT, $variant->id]], fixed: 15_000_000);

        $dto = $this->catalog()->findProduct($product->uuid);

        $this->assertSame(85_000_000, $dto->variants[0]->effectivePrice());
    }

    #[Test]
    public function a_brand_target_discounts_that_brands_products(): void
    {
        $brand = $this->makeBrand('Acme');
        $product = $this->makeProduct('Phone', 100_000_000, brandId: $brand->id);
        $other = $this->makeProduct('Other', 100_000_000);

        $this->automaticDiscount([[DiscountTargetType::BRAND, $brand->id]], bps: 1000);

        $this->assertSame(90_000_000, $this->catalog()->findProduct($product->uuid)->variants[0]->effectivePrice());
        $this->assertSame(100_000_000, $this->catalog()->findProduct($other->uuid)->variants[0]->effectivePrice());
    }

    #[Test]
    public function a_category_target_discounts_products_in_that_category(): void
    {
        $category = $this->makeCategory('Phones');
        $product = $this->makeProduct('Phone', 100_000_000, categoryId: $category->id);

        $this->automaticDiscount([[DiscountTargetType::CATEGORY, $category->id]], bps: 1000);

        $this->assertSame(90_000_000, $this->catalog()->findProduct($product->uuid)->variants[0]->effectivePrice());
    }

    #[Test]
    public function a_parent_category_discount_reaches_descendant_products(): void
    {
        // Electronics → Phones → Android; the product sits at the deepest level and
        // must still be caught by a rule aimed at the root.
        $electronics = $this->makeCategory('Electronics');
        $phones = $this->makeCategory('Phones', $electronics->id);
        $android = $this->makeCategory('Android', $phones->id);
        $product = $this->makeProduct('Pixel', 100_000_000, categoryId: $android->id);

        $this->automaticDiscount([[DiscountTargetType::CATEGORY, $electronics->id]], bps: 1000);

        $this->assertSame(90_000_000, $this->catalog()->findProduct($product->uuid)->variants[0]->effectivePrice());
    }

    #[Test]
    public function one_discount_can_target_many_entities_at_once(): void
    {
        $categoryA = $this->makeCategory('Cat A');
        $brand = $this->makeBrand('Brand B');
        $productA = $this->makeProduct('A', 100_000_000, categoryId: $categoryA->id);
        $productB = $this->makeProduct('B', 50_000_000, brandId: $brand->id);
        $productC = $this->makeProduct('C', 20_000_000);
        $untouched = $this->makeProduct('D', 10_000_000);

        $this->automaticDiscount([
            [DiscountTargetType::CATEGORY, $categoryA->id],
            [DiscountTargetType::BRAND, $brand->id],
            [DiscountTargetType::PRODUCT, $productC->id],
        ], bps: 1000);

        $this->assertSame(90_000_000, $this->catalog()->findProduct($productA->uuid)->variants[0]->effectivePrice());
        $this->assertSame(45_000_000, $this->catalog()->findProduct($productB->uuid)->variants[0]->effectivePrice());
        $this->assertSame(18_000_000, $this->catalog()->findProduct($productC->uuid)->variants[0]->effectivePrice());
        $this->assertSame(10_000_000, $this->catalog()->findProduct($untouched->uuid)->variants[0]->effectivePrice());
    }

    // ── Winner selection ──────────────────────────────────────────────────────

    #[Test]
    public function only_the_largest_reduction_applies_and_discounts_never_stack(): void
    {
        $category = $this->makeCategory('Phones');
        $product = $this->makeProduct('Phone', 100_000_000, categoryId: $category->id);
        $variant = $this->defaultVariant($product);

        $this->automaticDiscount([[DiscountTargetType::CATEGORY, $category->id]], bps: 1000, name: 'Category 10%');
        $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000, name: 'Product 20%');
        $this->automaticDiscount([[DiscountTargetType::VARIANT, $variant->id]], fixed: 15_000_000, name: 'Variant fixed');

        $dto = $this->catalog()->findProduct($product->uuid)->variants[0];

        // 20% (20,000,000) beats 15,000,000 fixed and 10% (10,000,000).
        // Stacking all three would have produced 55,000,000 off — it must not.
        $this->assertSame(20_000_000, $dto->automaticDiscount->discountAmount);
        $this->assertSame(80_000_000, $dto->effectivePrice());
        $this->assertSame('Product 20%', $dto->automaticDiscount->discountName);
    }

    // ── Active window ─────────────────────────────────────────────────────────

    #[Test]
    public function inactive_not_yet_started_expired_and_deleted_rules_are_all_ignored(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $target = [[DiscountTargetType::PRODUCT, $product->id]];

        $this->automaticDiscount($target, bps: 2000, isActive: false);
        $this->automaticDiscount($target, bps: 2500, startsAt: now()->addWeek()->toDateTimeString());
        $this->automaticDiscount($target, bps: 3000, endsAt: now()->subDay()->toDateTimeString());
        $this->automaticDiscount($target, bps: 3500)->delete();

        $dto = $this->catalog()->findProduct($product->uuid)->variants[0];

        $this->assertNull($dto->automaticDiscount);
        $this->assertSame(100_000_000, $dto->effectivePrice());
    }

    #[Test]
    public function a_rule_inside_its_window_applies(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $this->automaticDiscount(
            [[DiscountTargetType::PRODUCT, $product->id]],
            bps: 2000,
            startsAt: now()->subDay()->toDateTimeString(),
            endsAt: now()->addDay()->toDateTimeString(),
        );

        $this->assertSame(80_000_000, $this->catalog()->findProduct($product->uuid)->variants[0]->effectivePrice());
    }

    // ── Coupon rules stay out of the storefront ───────────────────────────────

    #[Test]
    public function a_coupon_backed_rule_never_affects_automatic_pricing(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        // scope=all does NOT mean "store-wide sale" — without a code it is inert.
        $this->coupon('SUMMER10', bps: 1000);

        $dto = $this->catalog()->findProduct($product->uuid)->variants[0];

        $this->assertNull($dto->automaticDiscount);
        $this->assertSame(100_000_000, $dto->effectivePrice());
    }

    // ── API shape ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_public_product_response_exposes_base_effective_and_discount(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000, name: 'Launch Offer');

        $this->getJson('/api/v1/catalog/products/'.$product->uuid)
            ->assertOk()
            ->assertJsonPath('variants.0.base_price', 100_000_000)
            ->assertJsonPath('variants.0.effective_price', 80_000_000)
            ->assertJsonPath('variants.0.discount.type', 'percentage')
            ->assertJsonPath('variants.0.discount.percentage_bps', 2000)
            ->assertJsonPath('variants.0.discount.amount', 20_000_000)
            ->assertJsonPath('variants.0.discount.name', 'Launch Offer');
    }

    #[Test]
    public function with_no_discount_effective_price_equals_base_and_discount_is_null(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);

        $this->getJson('/api/v1/catalog/products/'.$product->uuid)
            ->assertOk()
            ->assertJsonPath('variants.0.base_price', 100_000_000)
            ->assertJsonPath('variants.0.effective_price', 100_000_000)
            ->assertJsonPath('variants.0.discount', null);
    }

    // ── Batching ──────────────────────────────────────────────────────────────

    #[Test]
    public function a_product_listing_evaluates_promotions_in_batch_not_per_product(): void
    {
        $category = $this->makeCategory('Phones');

        for ($i = 0; $i < 10; $i++) {
            $this->makeProduct("Phone {$i}", 100_000_000, categoryId: $category->id);
        }

        $this->automaticDiscount([[DiscountTargetType::CATEGORY, $category->id]], bps: 1000);

        DB::enableQueryLog();
        $this->getJson('/api/v1/catalog/products?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.variants.0.effective_price', 90_000_000)
            ->assertJsonCount(10, 'data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $promotionQueries = collect($queries)
            ->filter(fn (array $q): bool => str_contains($q['query'], 'discount_targets') || str_contains($q['query'], '"discounts"'))
            ->count();

        // Two lookups total (targets, then the discounts they belong to) regardless
        // of page size — an N+1 regression would make this scale with the 10 products.
        $this->assertLessThanOrEqual(
            4,
            $promotionQueries,
            "Expected a batched promotion lookup, saw {$promotionQueries} promotion queries for 10 products."
        );
    }
}
