<?php

declare(strict_types=1);

namespace Tests\Feature\Promotion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Promotion\Domain\Enums\DiscountTargetType;
use PHPUnit\Framework\Attributes\Test;

/**
 * `has_discount` after the compare_at_price removal.
 *
 * The filter now means "at least one variant currently has an applicable active
 * automatic discount". The critical property is that it is a DB constraint, so
 * pagination counts stay truthful — filtering a fetched page in PHP would break
 * `total`, `last_page`, and every page past the first.
 */
class HasDiscountFilterTest extends PromotionTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedCatalogPermissions();
    }

    #[Test]
    public function has_discount_true_returns_only_discounted_products(): void
    {
        $discounted = $this->makeProduct('Discounted', 100_000_000);
        $this->makeProduct('Full price', 100_000_000);

        $this->automaticDiscount([[DiscountTargetType::PRODUCT, $discounted->id]], bps: 2000);

        $response = $this->getJson('/api/v1/catalog/products?has_discount=true')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($discounted->uuid, $response->json('data.0.id'));
    }

    #[Test]
    public function has_discount_false_returns_only_undiscounted_products(): void
    {
        $discounted = $this->makeProduct('Discounted', 100_000_000);
        $plain = $this->makeProduct('Full price', 100_000_000);

        $this->automaticDiscount([[DiscountTargetType::PRODUCT, $discounted->id]], bps: 2000);

        $response = $this->getJson('/api/v1/catalog/products?has_discount=false')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($plain->uuid, $response->json('data.0.id'));
    }

    #[Test]
    public function it_matches_products_reached_through_variant_category_and_brand_targets(): void
    {
        $brand = $this->makeBrand('Acme');
        $parent = $this->makeCategory('Electronics');
        $child = $this->makeCategory('Phones', $parent->id);

        $byVariant = $this->makeProduct('By variant', 100_000_000);
        $byCategory = $this->makeProduct('By descendant category', 100_000_000, categoryId: $child->id);
        $byBrand = $this->makeProduct('By brand', 100_000_000, brandId: $brand->id);
        $this->makeProduct('Untouched', 100_000_000);

        $this->automaticDiscount([[DiscountTargetType::VARIANT, $this->defaultVariant($byVariant)->id]], bps: 1000);
        // Aimed at the PARENT category — the product filed under the child must
        // still be counted as discounted.
        $this->automaticDiscount([[DiscountTargetType::CATEGORY, $parent->id]], bps: 1000);
        $this->automaticDiscount([[DiscountTargetType::BRAND, $brand->id]], bps: 1000);

        $response = $this->getJson('/api/v1/catalog/products?has_discount=true')->assertOk();

        $this->assertSame(3, $response->json('meta.total'));
        $this->assertEqualsCanonicalizing(
            [$byVariant->uuid, $byCategory->uuid, $byBrand->uuid],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    #[Test]
    public function expired_and_inactive_rules_do_not_make_a_product_discounted(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000, isActive: false);
        $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000, endsAt: now()->subDay()->toDateTimeString());

        $this->assertSame(0, $this->getJson('/api/v1/catalog/products?has_discount=true')->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/v1/catalog/products?has_discount=false')->json('meta.total'));
    }

    #[Test]
    public function a_coupon_rule_does_not_make_any_product_discounted(): void
    {
        $this->makeProduct('Phone', 100_000_000);
        $this->coupon('SUMMER10', bps: 1000);

        $this->assertSame(0, $this->getJson('/api/v1/catalog/products?has_discount=true')->json('meta.total'));
    }

    #[Test]
    public function with_no_promotions_at_all_the_filter_still_partitions_correctly(): void
    {
        $this->makeProduct('A', 1000);
        $this->makeProduct('B', 2000);

        $this->assertSame(0, $this->getJson('/api/v1/catalog/products?has_discount=true')->json('meta.total'));
        $this->assertSame(2, $this->getJson('/api/v1/catalog/products?has_discount=false')->json('meta.total'));
    }

    #[Test]
    public function the_filter_paginates_correctly_across_pages(): void
    {
        // 7 discounted + 5 not. Paginating 5 at a time must report total=7 and
        // last_page=2, and page 2 must hold the remaining 2 — all of which only
        // holds if the filter is part of the SQL that counts.
        $category = $this->makeCategory('Sale');

        for ($i = 0; $i < 7; $i++) {
            $this->makeProduct("Discounted {$i}", 100_000_000, categoryId: $category->id);
        }

        for ($i = 0; $i < 5; $i++) {
            $this->makeProduct("Plain {$i}", 100_000_000);
        }

        $this->automaticDiscount([[DiscountTargetType::CATEGORY, $category->id]], bps: 1000);

        $page1 = $this->getJson('/api/v1/catalog/products?has_discount=true&per_page=5&page=1')->assertOk();
        $this->assertSame(7, $page1->json('meta.total'));
        $this->assertSame(2, $page1->json('meta.last_page'));
        $this->assertCount(5, $page1->json('data'));

        $page2 = $this->getJson('/api/v1/catalog/products?has_discount=true&per_page=5&page=2')->assertOk();
        $this->assertCount(2, $page2->json('data'));

        // The two pages together are the 7 distinct discounted products.
        $ids = array_merge(
            collect($page1->json('data'))->pluck('id')->all(),
            collect($page2->json('data'))->pluck('id')->all(),
        );
        $this->assertCount(7, array_unique($ids));

        $this->assertSame(5, $this->getJson('/api/v1/catalog/products?has_discount=false')->json('meta.total'));
    }

    #[Test]
    public function a_product_matched_by_several_rules_is_not_duplicated(): void
    {
        $category = $this->makeCategory('Phones');
        $brand = $this->makeBrand('Acme');
        $product = $this->makeProduct('Phone', 100_000_000, categoryId: $category->id, brandId: $brand->id);

        $this->automaticDiscount([[DiscountTargetType::CATEGORY, $category->id]], bps: 1000);
        $this->automaticDiscount([[DiscountTargetType::BRAND, $brand->id]], bps: 2000);
        $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 500);

        $response = $this->getJson('/api/v1/catalog/products?has_discount=true')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertCount(1, $response->json('data'));
        // And the strongest rule (20% via brand) is the one priced.
        $this->assertSame(80_000_000, $response->json('data.0.variants.0.effective_price'));
    }

    // ── The base-price filters and sorts keep their old meaning ───────────────

    #[Test]
    public function min_and_max_price_still_filter_on_base_price_not_effective_price(): void
    {
        $cheap = $this->makeProduct('Cheap', 10_000_000);
        $pricey = $this->makeProduct('Pricey', 100_000_000);

        // A 90% cut would drop the pricey item to 10,000,000 — but price filters are
        // deliberately still base-price semantics, so it stays out of the range.
        $this->automaticDiscount([[DiscountTargetType::PRODUCT, $pricey->id]], bps: 9000);

        $response = $this->getJson('/api/v1/catalog/products?max_price=20000000')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($cheap->uuid, $response->json('data.0.id'));
    }

    #[Test]
    public function cheapest_and_most_expensive_sorts_still_order_by_base_price(): void
    {
        $a = $this->makeProduct('A', 10_000_000);
        $b = $this->makeProduct('B', 20_000_000);
        $c = $this->makeProduct('C', 30_000_000);

        // Discounting C to 3,000,000 would make it cheapest by effective price;
        // the sort must ignore that and keep base-price ordering.
        $this->automaticDiscount([[DiscountTargetType::PRODUCT, $c->id]], bps: 9000);

        $cheapest = $this->getJson('/api/v1/catalog/products?sort=cheapest')->assertOk();
        $this->assertSame(
            [$a->uuid, $b->uuid, $c->uuid],
            collect($cheapest->json('data'))->pluck('id')->all(),
        );

        $expensive = $this->getJson('/api/v1/catalog/products?sort=most_expensive')->assertOk();
        $this->assertSame(
            [$c->uuid, $b->uuid, $a->uuid],
            collect($expensive->json('data'))->pluck('id')->all(),
        );
    }
}
