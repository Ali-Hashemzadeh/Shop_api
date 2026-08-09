<?php

declare(strict_types=1);

namespace Tests\Feature\Promotion;

use Illuminate\Support\Str;
use Modules\Catalog\Domain\Models\Brand;
use Modules\Catalog\Domain\Models\Category;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Promotion\Domain\Contracts\PromotionManagerInterface;
use Modules\Promotion\Domain\Enums\DiscountScope;
use Modules\Promotion\Domain\Enums\DiscountTargetType;
use Modules\Promotion\Domain\Enums\DiscountTriggerType;
use Modules\Promotion\Domain\Enums\DiscountType;
use Modules\Promotion\Domain\Models\Coupon;
use Modules\Promotion\Domain\Models\Discount;
use Tests\TestCase;

/**
 * Shared fixtures for the Promotion feature suite.
 *
 * Builds real Catalog rows (products, variants, categories, brands) so the tests
 * exercise the genuine Catalog → Promotion path rather than a mocked contract.
 */
abstract class PromotionTestCase extends TestCase
{
    protected function promotion(): PromotionManagerInterface
    {
        return app(PromotionManagerInterface::class);
    }

    protected function makeCategory(string $name, ?int $parentId = null): Category
    {
        return Category::createWithPublicCode([
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
            'is_active' => true,
            'parent_id' => $parentId,
        ]);
    }

    protected function makeBrand(string $name): Brand
    {
        return Brand::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
            'is_active' => true,
        ]);
    }

    /** Published product with one default variant at $basePrice. */
    protected function makeProduct(string $title, int $basePrice, ?int $categoryId = null, ?int $brandId = null): Product
    {
        $product = Product::createWithPublicCode([
            'title' => $title,
            'slug' => Str::slug($title).'-'.uniqid(),
            'status' => 'published',
            'category_id' => $categoryId,
            'brand_id' => $brandId,
        ]);

        ProductVariant::createWithPublicCode([
            'product_id' => $product->id,
            'type' => 'color',
            'is_default' => true,
            'base_price' => $basePrice,
        ]);

        return $product->fresh('variants');
    }

    protected function defaultVariant(Product $product): ProductVariant
    {
        return $product->variants()->where('is_default', true)->firstOrFail();
    }

    /**
     * An active, targeted automatic discount.
     *
     * @param  array<int, array{0: DiscountTargetType, 1: int}>  $targets
     */
    protected function automaticDiscount(
        array $targets,
        ?int $bps = null,
        ?int $fixed = null,
        ?int $cap = null,
        int $priority = 0,
        bool $isActive = true,
        ?string $startsAt = null,
        ?string $endsAt = null,
        string $name = 'Test Discount',
    ): Discount {
        $discount = Discount::query()->create([
            'name' => $name,
            'trigger_type' => DiscountTriggerType::AUTOMATIC->value,
            'scope' => DiscountScope::TARGETED->value,
            'discount_type' => $bps !== null ? DiscountType::PERCENTAGE->value : DiscountType::FIXED_AMOUNT->value,
            'percentage_bps' => $bps,
            'fixed_amount' => $fixed,
            'max_discount_amount' => $cap,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_active' => $isActive,
            'priority' => $priority,
        ]);

        foreach ($targets as [$type, $id]) {
            $discount->targets()->create([
                'target_type' => $type->value,
                'target_id' => $id,
            ]);
        }

        return $discount;
    }

    /** A coupon-backed (store-wide, dormant) discount plus its code. */
    protected function coupon(
        string $code,
        ?int $bps = null,
        ?int $fixed = null,
        ?int $cap = null,
        ?int $minSubtotal = null,
        ?int $usageLimit = null,
        ?int $usageLimitPerUser = null,
        bool $isActive = true,
        bool $discountActive = true,
        ?string $startsAt = null,
        ?string $endsAt = null,
    ): Coupon {
        $discount = Discount::query()->create([
            'name' => "Coupon rule {$code}",
            'trigger_type' => DiscountTriggerType::COUPON->value,
            'scope' => DiscountScope::ALL->value,
            'discount_type' => $bps !== null ? DiscountType::PERCENTAGE->value : DiscountType::FIXED_AMOUNT->value,
            'percentage_bps' => $bps,
            'fixed_amount' => $fixed,
            'max_discount_amount' => $cap,
            'min_subtotal' => $minSubtotal,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_active' => $discountActive,
        ]);

        return Coupon::query()->create([
            'discount_id' => $discount->id,
            'code' => $code,
            'is_active' => $isActive,
            'usage_limit' => $usageLimit,
            'usage_limit_per_user' => $usageLimitPerUser,
        ]);
    }
}
