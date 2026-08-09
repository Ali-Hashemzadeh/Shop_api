<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Persistence\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Promotion\Domain\Contracts\PromotionManagerInterface;
use Modules\Promotion\Domain\DTOs\AutomaticDiscountContextDTO;
use Modules\Promotion\Domain\DTOs\AutomaticTargetDefinitionsDTO;
use Modules\Promotion\Domain\DTOs\CampaignDTO;
use Modules\Promotion\Domain\DTOs\CouponDTO;
use Modules\Promotion\Domain\DTOs\CouponQuoteDTO;
use Modules\Promotion\Domain\DTOs\CouponRedemptionDTO;
use Modules\Promotion\Domain\DTOs\DiscountDTO;
use Modules\Promotion\Domain\DTOs\DiscountTargetDTO;
use Modules\Promotion\Domain\Enums\DiscountScope;
use Modules\Promotion\Domain\Enums\DiscountTargetType;
use Modules\Promotion\Domain\Enums\DiscountTriggerType;
use Modules\Promotion\Domain\Enums\RedemptionStatus;
use Modules\Promotion\Domain\Exceptions\CouponRejectedException;
use Modules\Promotion\Domain\Models\Campaign;
use Modules\Promotion\Domain\Models\Coupon;
use Modules\Promotion\Domain\Models\CouponRedemption;
use Modules\Promotion\Domain\Models\Discount;
use Modules\Promotion\Domain\Models\DiscountTarget;
use Modules\Promotion\Domain\Services\AutomaticDiscountResolver;
use Modules\Promotion\Domain\Services\DiscountCalculator;

class EloquentPromotionManager implements PromotionManagerInterface
{
    public function __construct(
        private readonly DiscountCalculator $calculator,
        private readonly AutomaticDiscountResolver $resolver,
    ) {}

    // ── Automatic discounts ───────────────────────────────────────────────────

    public function evaluateAutomaticDiscounts(array $contexts): array
    {
        if ($contexts === []) {
            return [];
        }

        // Collect every id the whole batch could possibly match on, so the candidate
        // lookup below is ONE query for the entire page of products rather than one
        // per variant.
        $variantIds = [];
        $productIds = [];
        $categoryIds = [];
        $brandIds = [];

        foreach ($contexts as $context) {
            $variantIds[$context->variantId] = true;
            $productIds[$context->productId] = true;

            foreach ($context->categoryIds as $categoryId) {
                $categoryIds[(int) $categoryId] = true;
            }

            if ($context->brandId !== null) {
                $brandIds[$context->brandId] = true;
            }
        }

        $targets = $this->loadMatchingTargets(
            array_keys($variantIds),
            array_keys($productIds),
            array_keys($categoryIds),
            array_keys($brandIds),
        );

        if ($targets === []) {
            return [];
        }

        $discounts = Discount::query()
            ->currentlyActive()
            ->where('trigger_type', DiscountTriggerType::AUTOMATIC->value)
            ->where('scope', DiscountScope::TARGETED->value)
            ->whereIn('id', array_unique(array_map(
                static fn (DiscountTarget $target): int => $target->discount_id,
                $targets,
            )))
            ->get()
            ->keyBy('id');

        if ($discounts->isEmpty()) {
            return [];
        }

        // Index the targets so matching a context is pure in-memory lookup:
        //   [target_type][target_id] => [discount_id, ...]
        $index = [];
        foreach ($targets as $target) {
            if (! $discounts->has($target->discount_id)) {
                continue;
            }

            $index[$target->target_type->value][$target->target_id][] = $target->discount_id;
        }

        $results = [];

        foreach ($contexts as $context) {
            $candidates = $this->candidatesFor($context, $index, $discounts);

            if ($candidates === []) {
                continue;
            }

            $result = $this->resolver->resolve($context, $candidates);

            if ($result !== null) {
                $results[$context->variantId] = $result;
            }
        }

        return $results;
    }

    /**
     * Every target row that could match anything in the batch, in one query.
     *
     * @param  array<int, int>  $variantIds
     * @param  array<int, int>  $productIds
     * @param  array<int, int>  $categoryIds
     * @param  array<int, int>  $brandIds
     * @return array<int, DiscountTarget>
     */
    private function loadMatchingTargets(array $variantIds, array $productIds, array $categoryIds, array $brandIds): array
    {
        $pairs = [
            DiscountTargetType::VARIANT->value => $variantIds,
            DiscountTargetType::PRODUCT->value => $productIds,
            DiscountTargetType::CATEGORY->value => $categoryIds,
            DiscountTargetType::BRAND->value => $brandIds,
        ];

        $pairs = array_filter($pairs, static fn (array $ids): bool => $ids !== []);

        if ($pairs === []) {
            return [];
        }

        $query = DiscountTarget::query();
        $first = true;

        foreach ($pairs as $type => $ids) {
            $clause = static function ($q) use ($type, $ids) {
                $q->where('target_type', $type)->whereIn('target_id', $ids);
            };

            $first ? $query->where($clause) : $query->orWhere($clause);
            $first = false;
        }

        return $query->get()->all();
    }

    /**
     * The rules that match one variant, each tagged with the most specific target
     * type it matched through.
     *
     * A single discount may target both "product 15" and "category 7" and hit the
     * same variant twice; it is one candidate, and it is credited with the more
     * specific of the two, because that is what the tie-break should compare.
     *
     * @param  array<string, array<int, array<int, int>>>  $index
     * @param  Collection<int, Discount>  $discounts
     * @return array<int, array{discount: Discount, targetType: DiscountTargetType}>
     */
    private function candidatesFor(AutomaticDiscountContextDTO $context, array $index, $discounts): array
    {
        $matches = [];

        $lookups = [
            [DiscountTargetType::VARIANT, [$context->variantId]],
            [DiscountTargetType::PRODUCT, [$context->productId]],
            [DiscountTargetType::CATEGORY, array_map('intval', $context->categoryIds)],
            [DiscountTargetType::BRAND, $context->brandId === null ? [] : [$context->brandId]],
        ];

        foreach ($lookups as [$targetType, $ids]) {
            foreach ($ids as $id) {
                foreach ($index[$targetType->value][$id] ?? [] as $discountId) {
                    // Keep the first (most specific) match: $lookups is ordered
                    // variant → product → category → brand.
                    if (! isset($matches[$discountId])) {
                        $matches[$discountId] = [
                            'discount' => $discounts->get($discountId),
                            'targetType' => $targetType,
                        ];
                    }
                }
            }
        }

        return array_values($matches);
    }

    public function getActiveAutomaticTargetDefinitions(): AutomaticTargetDefinitionsDTO
    {
        $targets = DiscountTarget::query()
            ->whereIn('discount_id', Discount::query()
                ->currentlyActive()
                ->where('trigger_type', DiscountTriggerType::AUTOMATIC->value)
                ->where('scope', DiscountScope::TARGETED->value)
                ->select('id'))
            ->get(['target_type', 'target_id']);

        return $this->definitionsFromTargets($targets);
    }

    /**
     * @param  Collection<int, DiscountTarget>  $targets
     */
    private function definitionsFromTargets($targets): AutomaticTargetDefinitionsDTO
    {
        $grouped = [
            DiscountTargetType::PRODUCT->value => [],
            DiscountTargetType::VARIANT->value => [],
            DiscountTargetType::CATEGORY->value => [],
            DiscountTargetType::BRAND->value => [],
        ];

        foreach ($targets as $target) {
            $grouped[$target->target_type->value][] = (int) $target->target_id;
        }

        return new AutomaticTargetDefinitionsDTO(
            productIds: array_values(array_unique($grouped[DiscountTargetType::PRODUCT->value])),
            variantIds: array_values(array_unique($grouped[DiscountTargetType::VARIANT->value])),
            categoryIds: array_values(array_unique($grouped[DiscountTargetType::CATEGORY->value])),
            brandIds: array_values(array_unique($grouped[DiscountTargetType::BRAND->value])),
        );
    }

    // ── Coupons ───────────────────────────────────────────────────────────────

    public function normalizeCouponCode(string $code): string
    {
        return Coupon::normalizeCode($code);
    }

    public function quoteCoupon(string $code, int $userId, int $merchandiseSubtotal): CouponQuoteDTO
    {
        $coupon = $this->findUsableCoupon($code);

        $this->assertLimits($coupon, $userId);

        return $this->buildQuote($coupon, $merchandiseSubtotal);
    }

    public function reserveCouponForOrder(string $code, int $orderId, int $userId, int $merchandiseSubtotal): CouponQuoteDTO
    {
        return DB::transaction(function () use ($code, $orderId, $userId, $merchandiseSubtotal): CouponQuoteDTO {
            $normalized = $this->normalizeCouponCode($code);

            // Resolve first (no lock) purely to learn which row to lock.
            $coupon = $this->findUsableCoupon($normalized);

            // Take the row lock BEFORE counting. Counting and then inserting without
            // it is the classic oversell race: two checkouts both read "1 use left"
            // and both write a reservation.
            $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw CouponRejectedException::generic();
            }

            // Re-check every rule under the lock — the coupon may have been
            // deactivated or its discount expired between the two reads.
            $coupon = $this->findUsableCoupon($normalized);

            $existing = CouponRedemption::query()->where('order_id', $orderId)->first();

            if ($existing !== null) {
                // One coupon lifecycle per order. Re-reserving the same coupon is a
                // no-op so payment retries are safe; a different coupon is refused,
                // because the order's pricing is already committed to this one.
                if ($existing->coupon_id !== $coupon->id && $existing->status !== RedemptionStatus::RELEASED) {
                    throw new CouponRejectedException('The coupon for this order can no longer be changed.');
                }

                if ($existing->status !== RedemptionStatus::RELEASED) {
                    return $this->buildQuote($coupon, $merchandiseSubtotal);
                }

                // A previously released claim is reused rather than duplicated —
                // the unique index on order_id allows exactly one row per order.
                $this->assertLimits($coupon, $userId, excludeOrderId: $orderId);
                $quote = $this->buildQuote($coupon, $merchandiseSubtotal);

                $existing->update([
                    'coupon_id' => $coupon->id,
                    'user_id' => $userId,
                    'status' => RedemptionStatus::RESERVED->value,
                    'discount_amount' => $quote->discountAmount,
                ]);

                return $quote;
            }

            $this->assertLimits($coupon, $userId, excludeOrderId: $orderId);
            $quote = $this->buildQuote($coupon, $merchandiseSubtotal);

            CouponRedemption::query()->create([
                'coupon_id' => $coupon->id,
                'order_id' => $orderId,
                'user_id' => $userId,
                'status' => RedemptionStatus::RESERVED->value,
                'discount_amount' => $quote->discountAmount,
            ]);

            return $quote;
        });
    }

    public function redeemCouponForOrder(int $orderId): void
    {
        // Only a live reservation is promoted. An order with no coupon, or one
        // already redeemed by a repeated gateway callback, updates zero rows.
        CouponRedemption::query()
            ->where('order_id', $orderId)
            ->where('status', RedemptionStatus::RESERVED->value)
            ->update(['status' => RedemptionStatus::REDEEMED->value]);
    }

    public function releaseCouponForOrder(int $orderId): void
    {
        // Deliberately only releases a *reserved* claim: once an order is paid its
        // redemption is historical and must never be returned to the pool.
        CouponRedemption::query()
            ->where('order_id', $orderId)
            ->where('status', RedemptionStatus::RESERVED->value)
            ->update(['status' => RedemptionStatus::RELEASED->value]);
    }

    /**
     * Resolve a code to a coupon that is usable *today*, or reject it.
     *
     * Unknown, inactive, soft-deleted, and out-of-window codes all raise the same
     * generic message so the endpoint cannot be used to discover which codes exist.
     */
    private function findUsableCoupon(string $code): Coupon
    {
        $coupon = Coupon::query()
            ->with('discount')
            ->where('code', $this->normalizeCouponCode($code))
            ->first();

        if ($coupon === null || ! $coupon->is_active) {
            throw CouponRejectedException::generic();
        }

        $discount = $coupon->discount;

        if ($discount === null || $discount->trashed()) {
            throw CouponRejectedException::generic();
        }

        // A coupon may only ever be backed by a coupon-triggered, store-wide rule.
        // Anything else is a misconfiguration and must not price an order.
        if ($discount->trigger_type !== DiscountTriggerType::COUPON || $discount->scope !== DiscountScope::ALL) {
            throw CouponRejectedException::generic();
        }

        if (! $discount->isCurrentlyActive()) {
            throw new CouponRejectedException('This coupon is not currently available.');
        }

        return $coupon;
    }

    /**
     * Enforce the global and per-user usage allowances.
     *
     * Counts reserved + redeemed and never released, so an abandoned order returns
     * its use to the pool. $excludeOrderId lets the reserving order ignore its own
     * existing row when re-reserving.
     */
    private function assertLimits(Coupon $coupon, int $userId, ?int $excludeOrderId = null): void
    {
        if ($coupon->usage_limit !== null) {
            $used = $this->consumedCount($coupon->id, null, $excludeOrderId);

            if ($used >= $coupon->usage_limit) {
                throw new CouponRejectedException('This coupon has reached its usage limit.');
            }
        }

        if ($coupon->usage_limit_per_user !== null) {
            $usedByUser = $this->consumedCount($coupon->id, $userId, $excludeOrderId);

            if ($usedByUser >= $coupon->usage_limit_per_user) {
                throw new CouponRejectedException('You have already used this coupon the maximum number of times.');
            }
        }
    }

    private function consumedCount(int $couponId, ?int $userId, ?int $excludeOrderId): int
    {
        $query = CouponRedemption::query()
            ->where('coupon_id', $couponId)
            ->whereIn('status', RedemptionStatus::consumingStatuses());

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        if ($excludeOrderId !== null) {
            $query->where('order_id', '!=', $excludeOrderId);
        }

        return $query->count();
    }

    /**
     * Compute the reduction, after checking the minimum-spend rule.
     *
     * The basis is always the post-automatic merchandise subtotal — shipping and
     * tax are excluded from both the threshold test and the percentage, so a
     * coupon never discounts delivery.
     */
    private function buildQuote(Coupon $coupon, int $merchandiseSubtotal): CouponQuoteDTO
    {
        $discount = $coupon->discount;

        if ($discount->min_subtotal !== null && $merchandiseSubtotal < $discount->min_subtotal) {
            throw new CouponRejectedException(sprintf(
                'This coupon requires a minimum order of %s.',
                number_format((float) $discount->min_subtotal)
            ));
        }

        $amount = $this->calculator->reductionFor($discount, $merchandiseSubtotal);

        if ($amount <= 0) {
            throw new CouponRejectedException('This coupon does not reduce your order total.');
        }

        return new CouponQuoteDTO(
            couponId: $coupon->id,
            code: $coupon->code,
            discountId: $discount->id,
            discountName: $discount->name,
            discountType: $discount->discount_type,
            percentageBps: $discount->percentage_bps,
            fixedAmount: $discount->fixed_amount,
            maxDiscountAmount: $discount->max_discount_amount,
            discountAmount: $amount,
            merchandiseSubtotal: $merchandiseSubtotal,
        );
    }

    // ── Campaigns ─────────────────────────────────────────────────────────────

    public function getActiveCampaigns(bool $landingOnly = false): array
    {
        $query = Campaign::query()->currentlyActive();

        if ($landingOnly) {
            $query->where('show_on_landing', true);
        }

        return $query->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(static fn (Campaign $campaign): CampaignDTO => CampaignDTO::fromModel($campaign))
            ->all();
    }

    public function findActiveCampaignBySlug(string $slug): ?CampaignDTO
    {
        $campaign = Campaign::query()->currentlyActive()->where('slug', $slug)->first();

        return $campaign === null ? null : CampaignDTO::fromModel($campaign);
    }

    public function getCampaignTargetDefinitions(string $slug): ?AutomaticTargetDefinitionsDTO
    {
        $campaign = Campaign::query()->currentlyActive()->where('slug', $slug)->first();

        if ($campaign === null) {
            return null;
        }

        // Only the campaign's *currently active* automatic rules contribute products.
        // An expired rule inside a live campaign simply stops merchandising, which is
        // why the campaign and its rules are filtered independently.
        $discountIds = $campaign->discounts()
            ->currentlyActive()
            ->where('trigger_type', DiscountTriggerType::AUTOMATIC->value)
            ->where('scope', DiscountScope::TARGETED->value)
            ->pluck('discounts.id')
            ->all();

        if ($discountIds === []) {
            return new AutomaticTargetDefinitionsDTO;
        }

        return $this->definitionsFromTargets(
            DiscountTarget::query()->whereIn('discount_id', $discountIds)->get(['target_type', 'target_id'])
        );
    }

    // ── Admin reads ───────────────────────────────────────────────────────────

    public function getDiscounts(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Discount::query()->with('targets');

        if (! empty($filters['trigger_type'])) {
            $query->where('trigger_type', (string) $filters['trigger_type']);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['search'])) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }

        return $query->latest('id')
            ->paginate(min(max($perPage, 1), 100))
            ->through(fn (Discount $discount): DiscountDTO => $this->toDiscountDTO($discount));
    }

    public function findDiscount(int $id): ?DiscountDTO
    {
        $discount = Discount::query()->with('targets')->find($id);

        return $discount === null ? null : $this->toDiscountDTO($discount);
    }

    private function toDiscountDTO(Discount $discount): DiscountDTO
    {
        return DiscountDTO::fromModel(
            $discount,
            $discount->targets->map(
                static fn (DiscountTarget $target): DiscountTargetDTO => DiscountTargetDTO::fromModel($target)
            )->all(),
        );
    }

    public function getCoupons(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Coupon::query()->with('discount');

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['discount_id'])) {
            $query->where('discount_id', (int) $filters['discount_id']);
        }

        if (! empty($filters['search'])) {
            $query->where('code', 'like', '%'.$this->normalizeCouponCode((string) $filters['search']).'%');
        }

        $paginator = $query->latest('id')->paginate(min(max($perPage, 1), 100));

        // One grouped count for the whole page instead of a count per coupon.
        $usage = $this->usageCountsFor($paginator->getCollection()->pluck('id')->all());

        return $paginator->through(fn (Coupon $coupon): CouponDTO => CouponDTO::fromModel(
            $coupon,
            $usage[$coupon->id] ?? 0,
            $coupon->discount ? DiscountDTO::fromModel($coupon->discount) : null,
        ));
    }

    public function findCoupon(int $id): ?CouponDTO
    {
        $coupon = Coupon::query()->with('discount')->find($id);

        if ($coupon === null) {
            return null;
        }

        return CouponDTO::fromModel(
            $coupon,
            $this->usageCountsFor([$coupon->id])[$coupon->id] ?? 0,
            $coupon->discount ? DiscountDTO::fromModel($coupon->discount) : null,
        );
    }

    /**
     * @param  array<int, int>  $couponIds
     * @return array<int, int>
     */
    private function usageCountsFor(array $couponIds): array
    {
        if ($couponIds === []) {
            return [];
        }

        return CouponRedemption::query()
            ->whereIn('coupon_id', $couponIds)
            ->whereIn('status', RedemptionStatus::consumingStatuses())
            ->groupBy('coupon_id')
            ->selectRaw('coupon_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'coupon_id')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    public function getCampaigns(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Campaign::query()->withCount('discounts');

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (array_key_exists('show_on_landing', $filters) && $filters['show_on_landing'] !== null) {
            $query->where('show_on_landing', (bool) $filters['show_on_landing']);
        }

        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('slug', 'like', '%'.$filters['search'].'%');
            });
        }

        return $query->orderBy('sort_order')->orderByDesc('id')
            ->paginate(min(max($perPage, 1), 100))
            ->through(static fn (Campaign $campaign): CampaignDTO => CampaignDTO::fromModel(
                $campaign,
                (int) ($campaign->discounts_count ?? 0),
            ));
    }

    public function findCampaign(int $id): ?CampaignDTO
    {
        $campaign = Campaign::query()->withCount('discounts')->find($id);

        return $campaign === null
            ? null
            : CampaignDTO::fromModel($campaign, (int) ($campaign->discounts_count ?? 0));
    }

    public function getRedemptions(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = CouponRedemption::query()->with('coupon');

        foreach (['coupon_id', 'order_id', 'user_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, (int) $filters[$key]);
            }
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        return $query->latest('id')
            ->paginate(min(max($perPage, 1), 100))
            ->through(static fn (CouponRedemption $redemption): CouponRedemptionDTO => CouponRedemptionDTO::fromModel(
                $redemption,
                $redemption->coupon?->code,
            ));
    }
}
