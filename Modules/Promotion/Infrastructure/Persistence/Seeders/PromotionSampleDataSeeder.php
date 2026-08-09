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
 * The centrepiece is the مداد رنگی ۱۲ رنگ فابر کاستل: its default variant is hit by a category
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

        $coloredPencils = Product::query()->where('slug', 'faber-castell-12-color-pencils')->with('variants')->first();
        $pen = Product::query()->where('slug', 'panter-sp-101-pen')->first();
        $drawingPencil = Product::query()->where('slug', 'faber-castell-9000-drawing-pencil')->first();
        $stationeryColoredPencil = Category::query()->where('slug', 'stationery-colored-pencil')->first();
        $engineering = Category::query()->where('slug', 'engineering-and-architecture')->first();

        if ($coloredPencils === null || $stationeryColoredPencil === null) {
            $this->command?->warn('Promotion sample data skipped: catalog demo products not found.');

            return;
        }

        $coloredPencilsDefaultVariant = $coloredPencils->variants->firstWhere('is_default', true) ?? $coloredPencils->variants->first();

        // ── The overlap demo, on the مداد رنگی ۱۲ رنگ فابر کاستل default variant (45,000,000) ──
        //
        //   category "مداد رنگی تحریر" 10%  → 4,500,000
        //   product  مداد رنگی ۱۲ رنگ فابر کاستل 20%  → 9,000,000   ← winner (largest reduction)
        //   variant  default     6,000,000 fixed   → 6,000,000
        //
        // Effective price becomes 36,000,000. The other two rules do nothing for
        // this variant — automatic discounts never stack.
        $categoryRule = $this->discount([
            'name' => 'تخفیف دسته مداد رنگی تحریر',
            'description' => '۱۰٪ تخفیف برای محصولات دسته مداد رنگی تحریر و زیرمجموعه‌های آن.',
            'discount_type' => DiscountType::PERCENTAGE->value,
            'percentage_bps' => 1000,
        ], [
            [DiscountTargetType::CATEGORY, $stationeryColoredPencil->id],
        ]);

        $productRule = $this->discount([
            'name' => 'تخفیف ویژه مداد رنگی فابر کاستل',
            'description' => '۲۰٪ تخفیف برای تمام تنوع‌های مداد رنگی فابر کاستل.',
            'discount_type' => DiscountType::PERCENTAGE->value,
            'percentage_bps' => 2000,
        ], [
            [DiscountTargetType::PRODUCT, $coloredPencils->id],
        ]);

        $this->discount([
            'name' => 'تخفیف ثابت مداد رنگی فابر کاستل',
            'description' => 'تخفیف ثابت ۶,۰۰۰,۰۰۰ ریال که در برابر تخفیف ۲۰٪ محصول انتخاب نمی‌شود.',
            'discount_type' => DiscountType::FIXED_AMOUNT->value,
            'fixed_amount' => 6_000_000,
        ], $coloredPencilsDefaultVariant === null ? [] : [
            [DiscountTargetType::VARIANT, $coloredPencilsDefaultVariant->id],
        ]);

        // ── A rule spanning several products at once ──────────────────────────
        $multiTargetRule = $this->discount([
            'name' => 'تخفیف منتخب لوازم تحریر و مهندسی',
            'description' => 'یک قانون تخفیف برای چند محصول منتخب و یک دسته مرتبط.',
            'discount_type' => DiscountType::PERCENTAGE->value,
            'percentage_bps' => 1500,
            'max_discount_amount' => 20_000_000,
            'priority' => 5,
        ], array_values(array_filter([
            $pen ? [DiscountTargetType::PRODUCT, $pen->id] : null,
            $drawingPencil ? [DiscountTargetType::PRODUCT, $drawingPencil->id] : null,
            $engineering ? [DiscountTargetType::CATEGORY, $engineering->id] : null,
        ])));

        // ── A scheduled rule that is not live yet ─────────────────────────────
        $this->discount([
            'name' => 'پیش‌نمایش تخفیف نوروز (شروع ماه آینده)',
            'discount_type' => DiscountType::PERCENTAGE->value,
            'percentage_bps' => 2500,
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addWeeks(2),
        ], [
            [DiscountTargetType::CATEGORY, $stationeryColoredPencil->id],
        ]);

        // ── Coupon-backed rules: dormant until a code is supplied ─────────────
        $couponRule = Discount::query()->create([
            'name' => 'تخفیف خوش‌آمدگویی ۱۰٪',
            'description' => 'کد تخفیف ۱۰٪ برای کل سفارش با سقف ۱۰,۰۰۰,۰۰۰ ریال.',
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
            'name' => 'تخفیف ویژه VIP به مبلغ ۵,۰۰۰,۰۰۰ ریال',
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
            'name' => 'فروش ویژه تابستانه',
            'slug' => 'summer-sale',
            'description' => 'تخفیف‌های منتخب روی محصولات لوازم تحریر، هنری و مهندسی.',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'is_active' => true,
            'show_on_landing' => true,
            'sort_order' => 1,
        ]);

        $campaign->discounts()->sync([$categoryRule->id, $productRule->id, $multiTargetRule->id]);

        // An inactive campaign, so the public listing has something to exclude.
        Campaign::query()->create([
            'name' => 'بلک فرایدی (پیش‌نویس)',
            'slug' => 'black-friday',
            'description' => 'هنوز فعال نشده است.',
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
