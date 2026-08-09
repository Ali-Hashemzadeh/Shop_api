<?php

namespace Modules\Promotion\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Modules\Catalog\Domain\Models\Category;
use Modules\Catalog\Domain\Models\Product;
use Modules\Promotion\Domain\Enums\DiscountScope;
use Modules\Promotion\Domain\Enums\DiscountTargetType;
use Modules\Promotion\Domain\Enums\DiscountTriggerType;
use Modules\Promotion\Domain\Enums\DiscountType;
use Modules\Promotion\Domain\Models\Campaign;
use Modules\Promotion\Domain\Models\Coupon;
use Modules\Promotion\Domain\Models\Discount;

/**
 * Demo promotions, deliberately overlapping so winner selection is visible.
 *
 * The centrepiece is the Galaxy S25: its default variant is hit by a category
 * rule, a product rule, and a variant rule at once, and the storefront must show
 * exactly one of them — the largest actual rial reduction. See the table in run().
 *
 * NOTE: this seeder reads Catalog models to resolve demo ids into loose target
 * references. That is acceptable *only* because a seeder is dev tooling sitting
 * outside the module graph — the Promotion module itself never imports Catalog,
 * which is what keeps the dependency arrow one-way at runtime.
 */
class PromotionSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        if (Discount::query()->exists()) {
            return;
        }

        $galaxy = Product::query()->where('slug', 'galaxy-s25')->with('variants')->first();
        $iphone = Product::query()->where('slug', 'iphone-16')->first();
        $macbook = Product::query()->where('slug', 'macbook-pro-14')->first();
        $phones = Category::query()->where('slug', 'phones')->first();
        $accessories = Category::query()->where('slug', 'accessories')->first();

        if ($galaxy === null || $phones === null) {
            $this->command?->warn('Promotion sample data skipped: catalog demo products not found.');

            return;
        }

        $galaxyDefaultVariant = $galaxy->variants->firstWhere('is_default', true) ?? $galaxy->variants->first();

        // ── The overlap demo, on the Galaxy S25 default variant (45,000,000) ──
        //
        //   category "Phones"   10%  → 4,500,000
        //   product  Galaxy S25 20%  → 9,000,000   ← winner (largest reduction)
        //   variant  default     6,000,000 fixed   → 6,000,000
        //
        // Effective price becomes 36,000,000. The other two rules do nothing for
        // this variant — automatic discounts never stack.
        $categoryRule = $this->discount([
            'name' => 'Phones Category Sale',
            'description' => '10% off everything filed under Phones, including sub-categories.',
            'discount_type' => DiscountType::PERCENTAGE->value,
            'percentage_bps' => 1000,
        ], [
            [DiscountTargetType::CATEGORY, $phones->id],
        ]);

        $productRule = $this->discount([
            'name' => 'Galaxy S25 Launch Offer',
            'description' => '20% off every Galaxy S25 variant.',
            'discount_type' => DiscountType::PERCENTAGE->value,
            'percentage_bps' => 2000,
        ], [
            [DiscountTargetType::PRODUCT, $galaxy->id],
        ]);

        $this->discount([
            'name' => 'Galaxy S25 Base Model Rebate',
            'description' => 'Flat 6,000,000 rial rebate — loses to the 20% product rule.',
            'discount_type' => DiscountType::FIXED_AMOUNT->value,
            'fixed_amount' => 6_000_000,
        ], $galaxyDefaultVariant === null ? [] : [
            [DiscountTargetType::VARIANT, $galaxyDefaultVariant->id],
        ]);

        // ── A rule spanning several products at once ──────────────────────────
        $multiTargetRule = $this->discount([
            'name' => 'Flagship Bundle Promo',
            'description' => 'One rule targeting several products and a category.',
            'discount_type' => DiscountType::PERCENTAGE->value,
            'percentage_bps' => 1500,
            'max_discount_amount' => 20_000_000,
            'priority' => 5,
        ], array_values(array_filter([
            $iphone ? [DiscountTargetType::PRODUCT, $iphone->id] : null,
            $macbook ? [DiscountTargetType::PRODUCT, $macbook->id] : null,
            $accessories ? [DiscountTargetType::CATEGORY, $accessories->id] : null,
        ])));

        // ── A scheduled rule that is not live yet ─────────────────────────────
        $this->discount([
            'name' => 'Nowruz Preview (starts next month)',
            'discount_type' => DiscountType::PERCENTAGE->value,
            'percentage_bps' => 2500,
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addWeeks(2),
        ], [
            [DiscountTargetType::CATEGORY, $phones->id],
        ]);

        // ── Coupon-backed rules: dormant until a code is supplied ─────────────
        $couponRule = Discount::query()->create([
            'name' => 'Welcome 10%',
            'description' => 'Order-level 10% coupon, capped at 10,000,000 rials.',
            'trigger_type' => DiscountTriggerType::COUPON->value,
            // scope=all does NOT mean a store-wide sale: this rule affects nothing
            // until a valid code activates it on an order.
            'scope' => DiscountScope::ALL->value,
            'discount_type' => DiscountType::PERCENTAGE->value,
            'percentage_bps' => 1000,
            'max_discount_amount' => 10_000_000,
            'min_subtotal' => 20_000_000,
            'is_active' => true,
        ]);

        $vipRule = Discount::query()->create([
            'name' => 'VIP 5,000,000 Off',
            'trigger_type' => DiscountTriggerType::COUPON->value,
            'scope' => DiscountScope::ALL->value,
            'discount_type' => DiscountType::FIXED_AMOUNT->value,
            'fixed_amount' => 5_000_000,
            'min_subtotal' => 30_000_000,
            'is_active' => true,
        ]);

        Coupon::query()->create([
            'discount_id' => $couponRule->id,
            'code' => 'WELCOME10',
            'is_active' => true,
            'usage_limit_per_user' => 1,
        ]);

        // Globally limited: only three orders in total may ever claim this.
        Coupon::query()->create([
            'discount_id' => $vipRule->id,
            'code' => 'VIP500',
            'is_active' => true,
            'usage_limit' => 3,
            'usage_limit_per_user' => 1,
        ]);

        // ── A campaign grouping several *different* automatic rules ───────────
        // This is the point of campaigns: every product in the section can carry a
        // different discount, instead of being forced to share one.
        $campaign = Campaign::query()->create([
            'name' => 'Summer Sale',
            'slug' => 'summer-sale',
            'description' => 'Hand-picked deals across phones, laptops, and accessories.',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'is_active' => true,
            'show_on_landing' => true,
            'sort_order' => 1,
        ]);

        $campaign->discounts()->sync([$categoryRule->id, $productRule->id, $multiTargetRule->id]);

        // An inactive campaign, so the public listing has something to exclude.
        Campaign::query()->create([
            'name' => 'Black Friday (draft)',
            'slug' => 'black-friday',
            'description' => 'Not yet live.',
            'is_active' => false,
            'show_on_landing' => false,
            'sort_order' => 2,
        ]);

        $this->command?->info('Promotion sample data seeded: 5 discounts, 2 coupons, 2 campaigns.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{0: DiscountTargetType, 1: int}>  $targets
     */
    private function discount(array $attributes, array $targets): Discount
    {
        $discount = Discount::query()->create(array_merge([
            'trigger_type' => DiscountTriggerType::AUTOMATIC->value,
            // Automatic rules are always targeted — store-wide automatic discounts
            // are not supported.
            'scope' => DiscountScope::TARGETED->value,
            'is_active' => true,
            'priority' => 0,
        ], $attributes));

        foreach ($targets as [$type, $id]) {
            $discount->targets()->create([
                'target_type' => $type->value,
                'target_id' => $id,
            ]);
        }

        return $discount;
    }
}
