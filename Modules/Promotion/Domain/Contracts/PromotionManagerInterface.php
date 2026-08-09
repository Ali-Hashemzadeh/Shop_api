<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Promotion\Domain\DTOs\AutomaticDiscountContextDTO;
use Modules\Promotion\Domain\DTOs\AutomaticDiscountResultDTO;
use Modules\Promotion\Domain\DTOs\AutomaticTargetDefinitionsDTO;
use Modules\Promotion\Domain\DTOs\CampaignDTO;
use Modules\Promotion\Domain\DTOs\CouponDTO;
use Modules\Promotion\Domain\DTOs\CouponQuoteDTO;
use Modules\Promotion\Domain\DTOs\CouponRedemptionDTO;
use Modules\Promotion\Domain\DTOs\DiscountDTO;
use Modules\Promotion\Domain\Exceptions\CouponRejectedException;

/**
 * The entire public surface of the Promotion module.
 *
 * Promotion sits at the bottom of the dependency graph: it imports no Catalog,
 * Cart, Order, or Payment model, contract, or DTO. Everything it needs arrives as
 * primitives or Promotion-owned DTOs, which is what lets Catalog depend on it for
 * live pricing without creating a cycle.
 */
interface PromotionManagerInterface
{
    // ── Automatic discounts ───────────────────────────────────────────────────

    /**
     * Price a batch of variants in one pass.
     *
     * Batch-only by design: a product listing must never fan out into one
     * Promotion query per product. For each context every matching active
     * automatic discount is costed in rials and exactly ONE winner is chosen —
     * the largest actual reduction, with ties broken deterministically by target
     * specificity, then priority, then lowest discount id. Automatic discounts
     * never stack.
     *
     * Variants with no applicable discount are simply absent from the result.
     *
     * @param  array<int, AutomaticDiscountContextDTO>  $contexts
     * @return array<int, AutomaticDiscountResultDTO> keyed by variant id
     */
    public function evaluateAutomaticDiscounts(array $contexts): array;

    /**
     * Every Catalog id currently reached by an active automatic discount.
     *
     * Lets Catalog express "on sale" as its own SQL constraint (the has_discount
     * filter) so pagination stays correct, rather than filtering a fetched page in
     * PHP. Category ids come back exactly as targeted — Catalog expands them to
     * descendants, since Catalog owns the hierarchy.
     */
    public function getActiveAutomaticTargetDefinitions(): AutomaticTargetDefinitionsDTO;

    // ── Coupons ───────────────────────────────────────────────────────────────

    /** Canonical storage/lookup form of a customer-typed code (trim + uppercase). */
    public function normalizeCouponCode(string $code): string;

    /**
     * What this coupon would take off the given post-automatic merchandise subtotal.
     *
     * Read-only and advisory: reserves nothing, mutates nothing, and is safe to call
     * repeatedly. The state it reports can change before payment, so payment-time
     * reservation revalidates from scratch.
     *
     * @param  int  $merchandiseSubtotal  Sum of order item line totals, already net of
     *                                    automatic discounts and excluding shipping and tax.
     *
     * @throws CouponRejectedException when the code is unusable
     */
    public function quoteCoupon(string $code, int $userId, int $merchandiseSubtotal): CouponQuoteDTO;

    /**
     * Claim this coupon for an order, concurrency-safely.
     *
     * Locks the coupon row FOR UPDATE, re-checks every rule, counts reserved +
     * redeemed against the global and per-user limits, and only then writes the
     * reservation — so two simultaneous checkouts cannot both take the last use.
     * The unique index on coupon_redemptions.order_id is the final guarantee.
     *
     * Idempotent for the same (order, coupon): re-reserving returns the existing
     * claim rather than creating a second one.
     *
     * @throws CouponRejectedException when the code is unusable or already claimed
     *                                 by this order under a different code
     */
    public function reserveCouponForOrder(string $code, int $orderId, int $userId, int $merchandiseSubtotal): CouponQuoteDTO;

    /**
     * Promote this order's reservation to redeemed. Called from the shared paid
     * path, so online capture and in-person payment behave identically.
     *
     * Idempotent and safe when the order never had a coupon — a repeated gateway
     * callback must not double-redeem.
     */
    public function redeemCouponForOrder(int $orderId): void;

    /**
     * Return this order's claim to the pool. Called from the shared cancellation
     * primitive, so customer cancel, admin cancel, TTL expiry, and pending-order
     * replacement all release consistently.
     *
     * Idempotent, and deliberately NOT called on payment failure: a failed attempt
     * leaves the order payable, so the claim must survive for the retry.
     */
    public function releaseCouponForOrder(int $orderId): void;

    // ── Campaigns ─────────────────────────────────────────────────────────────

    /**
     * Publicly visible campaigns, ordered by sort_order then id.
     *
     * @param  bool  $landingOnly  Restrict to campaigns flagged for the landing page.
     * @return array<int, CampaignDTO>
     */
    public function getActiveCampaigns(bool $landingOnly = false): array;

    /** One publicly visible campaign by slug, or null when missing/inactive/expired. */
    public function findActiveCampaignBySlug(string $slug): ?CampaignDTO;

    /**
     * The Catalog ids reached by a campaign's linked, currently-active automatic
     * discounts. Null when the campaign itself is not publicly visible.
     *
     * Catalog turns these into a product query using its own tables — Promotion
     * never assembles a product list, which is what keeps the campaign feature free
     * of a Catalog dependency.
     */
    public function getCampaignTargetDefinitions(string $slug): ?AutomaticTargetDefinitionsDTO;

    // ── Admin reads ───────────────────────────────────────────────────────────

    /**
     * Admin discount listing. Filters: trigger_type, is_active, search (name).
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<DiscountDTO>
     */
    public function getDiscounts(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function findDiscount(int $id): ?DiscountDTO;

    /**
     * Admin coupon listing. Filters: is_active, discount_id, search (code).
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<CouponDTO>
     */
    public function getCoupons(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function findCoupon(int $id): ?CouponDTO;

    /**
     * Admin campaign listing (every status). Filters: is_active, show_on_landing, search.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<CampaignDTO>
     */
    public function getCampaigns(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function findCampaign(int $id): ?CampaignDTO;

    /**
     * Coupon redemption / usage history. Filters: coupon_id, order_id, user_id, status.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<CouponRedemptionDTO>
     */
    public function getRedemptions(array $filters = [], int $perPage = 15): LengthAwarePaginator;
}
