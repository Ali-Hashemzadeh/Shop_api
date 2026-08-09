<?php

declare(strict_types=1);

namespace Tests\Feature\Promotion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Promotion\Domain\Enums\DiscountTargetType;
use Modules\Promotion\Domain\Models\Campaign;
use PHPUnit\Framework\Attributes\Test;

/**
 * Campaign merchandising.
 *
 * A campaign answers "why is this product shown here?". It never answers "what
 * does it cost?" — pricing stays with the global winner, even when that is a rule
 * from outside the campaign.
 */
class CampaignTest extends PromotionTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedCatalogPermissions();
        $this->seedPromotionPermissions();
    }

    private function campaign(array $attributes = []): Campaign
    {
        return Campaign::query()->create(array_merge([
            'name' => 'Summer Sale',
            'slug' => 'summer-sale',
            'is_active' => true,
            'show_on_landing' => true,
            'sort_order' => 0,
        ], $attributes));
    }

    // ── Public visibility ─────────────────────────────────────────────────────

    #[Test]
    public function the_public_list_shows_active_campaigns_with_customer_safe_fields(): void
    {
        $this->campaign(['description' => 'Great deals']);

        $this->getJson('/api/v1/catalog/campaigns')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'summer-sale')
            ->assertJsonPath('data.0.name', 'Summer Sale')
            ->assertJsonPath('data.0.description', 'Great deals')
            // Operational flags must not leak to the storefront.
            ->assertJsonMissingPath('data.0.is_active')
            ->assertJsonMissingPath('data.0.show_on_landing')
            ->assertJsonMissingPath('data.0.sort_order')
            ->assertJsonMissingPath('data.0.discount_count');
    }

    #[Test]
    public function inactive_future_and_expired_campaigns_are_hidden(): void
    {
        $this->campaign(['slug' => 'inactive', 'is_active' => false]);
        $this->campaign(['slug' => 'future', 'starts_at' => now()->addWeek()]);
        $this->campaign(['slug' => 'expired', 'ends_at' => now()->subDay()]);

        $this->getJson('/api/v1/catalog/campaigns')->assertOk()->assertJsonCount(0, 'data');

        foreach (['inactive', 'future', 'expired'] as $slug) {
            $this->getJson("/api/v1/catalog/campaigns/{$slug}")->assertStatus(404);
            $this->getJson("/api/v1/catalog/campaigns/{$slug}/products")->assertStatus(404);
        }
    }

    #[Test]
    public function the_landing_filter_excludes_campaigns_not_flagged_for_it(): void
    {
        $this->campaign(['slug' => 'on-landing', 'show_on_landing' => true]);
        $this->campaign(['slug' => 'off-landing', 'show_on_landing' => false]);

        $this->getJson('/api/v1/catalog/campaigns')->assertOk()->assertJsonCount(2, 'data');

        $landing = $this->getJson('/api/v1/catalog/campaigns?landing=true')->assertOk();
        $this->assertSame(['on-landing'], collect($landing->json('data'))->pluck('slug')->all());
    }

    // ── Product resolution ────────────────────────────────────────────────────

    #[Test]
    public function product_variant_category_and_brand_targets_all_resolve_to_products(): void
    {
        $brand = $this->makeBrand('Acme');
        $parent = $this->makeCategory('Electronics');
        $child = $this->makeCategory('Phones', $parent->id);

        $byProduct = $this->makeProduct('By product', 10_000_000);
        $byVariant = $this->makeProduct('By variant', 20_000_000);
        $byCategory = $this->makeProduct('By descendant category', 30_000_000, categoryId: $child->id);
        $byBrand = $this->makeProduct('By brand', 40_000_000, brandId: $brand->id);
        $this->makeProduct('Outside the campaign', 50_000_000);

        $campaign = $this->campaign();
        $campaign->discounts()->sync([
            $this->automaticDiscount([[DiscountTargetType::PRODUCT, $byProduct->id]], bps: 1000)->id,
            // A variant target resolves to its OWNING product.
            $this->automaticDiscount([[DiscountTargetType::VARIANT, $this->defaultVariant($byVariant)->id]], bps: 1000)->id,
            // Aimed at the parent category; the child's product must be included.
            $this->automaticDiscount([[DiscountTargetType::CATEGORY, $parent->id]], bps: 1000)->id,
            $this->automaticDiscount([[DiscountTargetType::BRAND, $brand->id]], bps: 1000)->id,
        ]);

        $response = $this->getJson('/api/v1/catalog/campaigns/summer-sale/products')->assertOk();

        $this->assertSame(4, $response->json('meta.total'));
        $this->assertEqualsCanonicalizing(
            [$byProduct->uuid, $byVariant->uuid, $byCategory->uuid, $byBrand->uuid],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    #[Test]
    public function a_product_matched_by_several_campaign_rules_appears_once(): void
    {
        $brand = $this->makeBrand('Acme');
        $category = $this->makeCategory('Phones');
        $product = $this->makeProduct('Phone', 100_000_000, categoryId: $category->id, brandId: $brand->id);

        $campaign = $this->campaign();
        $campaign->discounts()->sync([
            $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 500)->id,
            $this->automaticDiscount([[DiscountTargetType::CATEGORY, $category->id]], bps: 500)->id,
            $this->automaticDiscount([[DiscountTargetType::BRAND, $brand->id]], bps: 500)->id,
        ]);

        $response = $this->getJson('/api/v1/catalog/campaigns/summer-sale/products')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertCount(1, $response->json('data'));
    }

    #[Test]
    public function different_products_in_one_campaign_keep_their_own_different_discounts(): void
    {
        // This is the whole point of campaigns: one section, many rules.
        $cheap = $this->makeProduct('Ten off', 100_000_000);
        $deep = $this->makeProduct('Thirty off', 100_000_000);

        $campaign = $this->campaign();
        $campaign->discounts()->sync([
            $this->automaticDiscount([[DiscountTargetType::PRODUCT, $cheap->id]], bps: 1000)->id,
            $this->automaticDiscount([[DiscountTargetType::PRODUCT, $deep->id]], bps: 3000)->id,
        ]);

        $products = collect($this->getJson('/api/v1/catalog/campaigns/summer-sale/products')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertSame(90_000_000, $products[$cheap->uuid]['variants'][0]['effective_price']);
        $this->assertSame(70_000_000, $products[$deep->uuid]['variants'][0]['effective_price']);
    }

    #[Test]
    public function a_stronger_unrelated_discount_still_wins_the_displayed_price(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);

        // The campaign links a weak 10% rule…
        $campaignRule = $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 1000, name: 'Campaign 10%');
        $campaign = $this->campaign();
        $campaign->discounts()->sync([$campaignRule->id]);

        // …while an unrelated 20% rule exists outside it.
        $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000, name: 'Global 20%');

        $response = $this->getJson('/api/v1/catalog/campaigns/summer-sale/products')->assertOk();

        // Membership comes from the campaign; the price comes from the global winner.
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame(80_000_000, $response->json('data.0.variants.0.effective_price'));
        $this->assertSame('Global 20%', $response->json('data.0.variants.0.discount.name'));
    }

    #[Test]
    public function an_expired_linked_rule_contributes_no_products_but_the_campaign_still_exists(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $campaign = $this->campaign();
        $campaign->discounts()->sync([
            $this->automaticDiscount(
                [[DiscountTargetType::PRODUCT, $product->id]],
                bps: 1000,
                endsAt: now()->subDay()->toDateTimeString(),
            )->id,
        ]);

        // 200 with an empty page, not a 404 — the campaign is live, its rule is not.
        $this->getJson('/api/v1/catalog/campaigns/summer-sale/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function campaign_products_use_the_normal_product_resource_shape(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $campaign = $this->campaign();
        $campaign->discounts()->sync([
            $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000)->id,
        ]);

        $this->getJson('/api/v1/catalog/campaigns/summer-sale/products')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'title', 'slug', 'status', 'category_id', 'brand_id', 'variants']], 'meta', 'links'])
            ->assertJsonPath('data.0.id', $product->uuid);
    }

    // ── Admin management ──────────────────────────────────────────────────────

    #[Test]
    public function an_admin_can_create_a_campaign_and_link_automatic_discounts(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct('Phone', 100_000_000);
        $ruleA = $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 1000);
        $ruleB = $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000);

        $this->postJson('/api/v1/admin/promotions/campaigns', [
            'name' => 'Winter Sale',
            'slug' => 'winter-sale',
            'is_active' => true,
            'show_on_landing' => true,
            'discount_ids' => [$ruleA->id, $ruleB->id],
        ])
            ->assertCreated()
            ->assertJsonPath('slug', 'winter-sale')
            ->assertJsonPath('discount_count', 2);
    }

    #[Test]
    public function a_coupon_backed_discount_cannot_be_linked_to_a_campaign(): void
    {
        $this->actingAsAdmin();
        $coupon = $this->coupon('SUMMER10', bps: 1000);

        $this->postJson('/api/v1/admin/promotions/campaigns', [
            'name' => 'Bad Campaign',
            'slug' => 'bad-campaign',
            'discount_ids' => [$coupon->discount_id],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount_ids');
    }

    #[Test]
    public function campaign_slugs_are_unique(): void
    {
        $this->actingAsAdmin();
        $this->campaign(['slug' => 'taken']);

        $this->postJson('/api/v1/admin/promotions/campaigns', [
            'name' => 'Another',
            'slug' => 'taken',
        ])->assertStatus(422)->assertJsonValidationErrors('slug');
    }
}
