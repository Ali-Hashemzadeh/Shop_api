# Release Notes

## [Unreleased]

### Feature — Analytics: Event-driven read-model and reporting system (Hardened)

**A new, decoupled Analytics module (`Modules/Analytics`) provides high-performance reporting and analytics for admin dashboards.**
Analytics functions strictly as a read-model/reporting authority: it never performs cross-module queries or imports other modules' Eloquent models. It listens to published integration events across Order, Payment, and Shipment, maintaining self-contained aggregated tables.

- **Event Idempotency Protection:**
  - All integration domain events carry immutable string `$eventId` UUIDs.
  - Listeners execute inside `DB::transaction()` and check/insert `analytics_processed_events` to ensure duplicate event deliveries (queue retries, worker restarts) are deduplicated without double-counting.
- **Delivery Metric Accuracy:**
  - `analytics_delivery_stats` and `analytics_driver_stats` store additive `total_delivery_minutes` alongside delivery counts rather than pre-averaged numbers.
  - Average delivery duration is computed dynamically at query/resource time, ensuring mathematically exact weighted averages across arbitrary date ranges.
- **Financial Analytics Safety:**
  - Revenue is strictly incremented by `OrderPaidEvent` (and refunded by `OrderCancelledEvent`).
  - `PaymentSuccessfulEvent`, `PaymentFailedEvent`, and `PaymentCancelledEvent` exclusively update gateway stats in `analytics_payment_stats`, preventing any risk of double-counting sales revenue.
- **Hierarchical Category Analytics & Self-Contained Index:**
  - When products are purchased, sales metrics propagate up the entire category ancestor tree (immediate category and all ancestors are updated atomically).
  - The `analytics_product_categories` event index enables category-filtered product sales reporting without cross-module queries to Catalog tables.
- **Prerequisite Integration Enhancements:**
  - `OrderPaidEvent` enriched with immutable purchase payload: `discountAmount`, `couponAmount`, `couponId`, `paidAt`, `eventId`, and item list (`OrderPaidItemDTO`) carrying `productId`, `variantId`, `quantity`, `unitPrice`, `discountAmount`, `categoryIds`, `discountId`, and `regularUnitPrice`.
  - `ProductVariantDTO` carries `productId` and `categoryIds` resolved from `CategoryHierarchy::ancestorsFor()`.
  - Added `PaymentSuccessfulEvent` and `PaymentCancelledEvent` in `Modules/Payment/Domain/Events/`.
  - Added `ShipmentDeliveryFailedEvent` and duration metrics in `Modules/Shipment/Domain/Events/`.
- **Database Schema:** 10 analytics aggregate tables + 1 index table + 1 processed events table (store IDs only, zero cross-module FKs):
  `analytics_daily_sales`, `analytics_product_sales`, `analytics_variant_sales`, `analytics_category_sales`, `analytics_customer_stats`, `analytics_payment_stats`, `analytics_delivery_stats`, `analytics_driver_stats`, `analytics_discount_usage`, `analytics_coupon_usage`, `analytics_product_categories`, `analytics_processed_events`.
- **Admin APIs (`analytics.view` permission):**
  - `GET /api/v1/admin/analytics/dashboard` (revenue summary, order summary, best products, best categories, customer lifetime summary)
  - `GET /api/v1/admin/analytics/sales` (daily sales/revenue timeline with `from`/`to` filters)
  - `GET /api/v1/admin/analytics/products` (best-selling products and variants with `from`, `to`, `category_id` filters)
  - `GET /api/v1/admin/analytics/customers` (paginated customer stats with sorting and pagination)
  - `GET /api/v1/admin/analytics/delivery` (delivery method and driver stats with exact weighted delivery duration averages)
- **Tests Added:** 41 feature tests in `tests/Feature/Analytics/` across 9 test classes (`OrderPaidAnalyticsTest`, `OrderCancelledAnalyticsTest`, `PaymentAnalyticsTest`, `ShipmentAnalyticsTest`, `AnalyticsAuthorizationTest`, `AdminAnalyticsApiTest`, `AnalyticsEventIdempotencyTest`, `DeliveryAnalyticsAccuracyTest`, `FinancialAnalyticsSafetyTest`).

### Fix — Catalog: hierarchical category product filtering

**Product filtering by category is now hierarchy-aware.** Selecting a category includes products assigned directly to the chosen category as well as all of its descendant subcategories recursively at arbitrary depth. Ancestors and sibling categories are strictly excluded.

- **Reused existing scoped service:** Product filtering in `EloquentCatalogManager::applyProductFilters` expands category IDs before SQL pagination via `CategoryHierarchy::descendantsOf()`. The tree is loaded once per request in a single lightweight `id => parent_id` query and traversed in-memory without recursive N+1 database queries.
- **Pre-pagination SQL resolution:** Descendant IDs are resolved into a SQL `whereIn('category_id', $categoryIds)` constraint before pagination, ensuring `meta.total`, `meta.last_page`, per-page pagination item distributions, and sorting (`cheapest`, `most_expensive`, `most_sold`, latest) remain exact.
- **Affected endpoints:**
  - `GET /api/v1/catalog/products?category_id={id}`
  - `GET /api/v1/catalog/categories/{categoryId}/products`
  - `GET /api/v1/catalog/products/admin?category_id={id}`
  - `GET /api/v1/catalog/campaigns/{slug}/products?category_id={id}`
- **Validation preserved:** FormRequest validation (`IndexProductsRequest`, `IndexAdminProductsRequest`) validates `category_id` via `exists:categories,id` before tree expansion, returning `422 Unprocessable Entity` for invalid category IDs.
- **Tests added:** Full unit test suite `CategoryHierarchyTest` and feature test suite `CategoryHierarchyProductFilterTest` with realistic 4-level category hierarchy fixtures covering root, intermediate, leaf (with and without products), sibling exclusion, ancestor exclusion, filter composition, pagination, and admin/campaign routes.

### Feature — Notification refinements: opt-in admin SMS, and one event per fulfillment moment

**The paid-order admin SMS is now opt-in per admin.** Every admin still receives the in-app
`admin_order_paid` notification — that part is not configurable and did not change. What changed
is that the SMS goes only to the admins selected in the new recipient settings: a shop with a
dozen admin accounts does not have a dozen people who want a text at 3am for every order.

- **Storage is Notification-owned and deliberately generic.** New `notification_recipient_preferences`
  table (`user_id`, `notification_type`, `channel`, `enabled`, unique on the triple). No
  business-specific column was added to `users`, and no FK crosses into Identity — `user_id` is a
  loose reference, exactly like `notifications.user_id`. The current use is
  `admin_order_paid` + `sms`; the shape supports the next one without a migration.
- **Admin API:** `GET` / `PUT /api/v1/admin/notifications/admin-order-paid-sms-recipients`, behind
  the new `notification.admin-sms-recipients.manage` permission (seeded to `admin` only, not to
  `customer`). `GET` returns **every** admin with an `enabled` flag so the picker renders from one
  call; `PUT` takes `{"user_ids": [...]}` and makes that the entire selected set. An empty array is
  valid and means "nobody". The replacement is transactional and idempotent, and an admin removed
  from the list keeps a row flipped to `enabled: false` rather than being deleted — the row is the
  record of a decision.
- **Membership is verified through the contract, not a query.** Every submitted id is checked with
  `IdentityManagerInterface::isAdmin()` and a non-admin id is a **422 on `user_ids.{i}`** before any
  write, so a partially applied list can never be observed. `exists:users,id` would have been both a
  cross-module query and the wrong question — a customer id exists too.
- **Selected admins go through the normal per-user pipeline.** No `getAdminPhones()`, no bulk send:
  each recipient gets one `NotificationRequestDTO` on `[database, sms]`, so phone resolution,
  provider skips, delivery auditing and failure isolation all keep working. An admin with no phone
  is *skipped* and the order still pays.
- **Its own template, `admin_order_paid`** (`OrderId` = order public code) — never the customer's
  `payment_success` receipt, which says something else entirely.
- New `IdentityManagerInterface::getAdminUserSummaries()`: the same audience as `getAdminUserIds()`,
  but with the name and phone the picker has to show, resolved in one query instead of one per id.

**`ShipmentSentEvent` is split into one event per business moment.** Handing a parcel to the post
office and putting a courier on the road were never the same thing to say to a customer, and only
one of them has a tracking number to say it with. New primitives-only
`ShipmentReadyForPickupEvent`, `ShipmentHandedToPostEvent` and `ShipmentOutForDeliveryEvent`
replace it, each with a dedicated `NotificationType`, `NotificationTemplate` and after-commit listener.

- **Pickup finally gets told.** `ready_for_pickup` now notifies the customer in-app **and** by SMS
  (`shipment_ready_for_pickup`, `OrderId`). It is the one message a pickup customer needs, because
  nothing will arrive at their door to remind them. `picked_up` stays silent — they are standing there.
- **Postal handoff** raises `shipment_handed_to_post` (`OrderId` + `TrackingCode`) with postal wording
  and no reference to any delivery code. `handed_to_post` remains the last postal state this system
  tracks.
- **Local dispatch** raises `shipment_out_for_delivery` (`OrderId` + `DeliveryCode`) — still exactly
  **one** customer SMS carrying both, and still no code of any kind in the stored notification's
  `data`. The verification-code machinery from the delivery-worker release is reused unchanged:
  same minting inside the transition lock, same hash-only storage, same per-attempt invalidation.
  The resend path now uses this same template, because a resend repeats that one message with a
  fresh code rather than saying something new.
- **Legacy is left alone.** `NotificationType::SHIPMENT_SENT` and the `shipment_sent` /
  `shipment_sent_delivery_code` templates stay defined and mapped in `config/sms.php` so existing
  `.env` files and historical notification rows keep working; nothing emits them any more.
  Historical notifications are never rewritten, so a client must still render `shipment_sent`.
- New env keys: `SMS_SMSIR_ADMIN_ORDER_PAID_TEMPLATE_ID`,
  `SMS_SMSIR_SHIPMENT_READY_FOR_PICKUP_TEMPLATE_ID`, `SMS_SMSIR_SHIPMENT_HANDED_TO_POST_TEMPLATE_ID`,
  `SMS_SMSIR_SHIPMENT_OUT_FOR_DELIVERY_TEMPLATE_ID`. **Deployment note:** an operator who had
  `SMS_SMSIR_SHIPMENT_SENT*` configured must point the new keys at their templates — an unset
  template id skips the message rather than failing it, so the symptom is silence, not an error.

**Frontend handoff:** `FRONTEND_NOTIFICATION_SMS_CHANGES.html` documents the new admin settings API,
the three new notification types, and the UI branching that has to change.

### Feature — Delivery workers: assignment, driver API, and customer handoff codes

**A new `delivery` role, always held *alongside* `customer`.** A courier is a shopper who also delivers, so the role carries only the two extra fulfillment permissions and never a copy of the customer bundle. Granting it is additive — `POST /api/v1/admin/users/{user}/delivery-role` adds `delivery`, ensures `customer`, and touches nothing else; calling it twice changes nothing. A user with no phone number is refused: they could neither receive an assignment SMS nor sign in through the OTP flow.

**Admin user creation.** New `POST /api/v1/admin/users` creates a `customer` or a `delivery` account. No password and no temporary secret is minted — the phone number *is* the credential, and the account signs in through the existing OTP flow and may add a password itself afterwards. `admin` is not a creatable role, and creating somebody directly as `delivery` requires `profile.assign-delivery` **in addition to** `profile.create-any`, so the create endpoint cannot be used as a way around the grant endpoint. `GET /api/v1/admin/users` gained `?role=`, and admin user responses now expose `roles`; `GET /me` additionally exposes `permissions`, since authorization here is permission-based and a role name alone does not tell a client what it may do.

**Only shipments are assignable, never orders**, and only local deliveries: a postal parcel is handed to a carrier and a pickup is collected at the counter. `POST /api/v1/admin/shipments/{publicCode}/assign-delivery` also rejects finished (delivered/cancelled) shipments, non-delivery users, and delivery users without a phone. Reassignment is first-class — it closes the open row in the new append-only `shipment_delivery_assignments` table and opens another under one transaction, so the audit never shows two people holding one shipment at the same moment. Assigning the driver who already holds it is a no-op that notifies nobody.

**The assigned courier is told twice: in-app and by SMS**, via the new primitives-only `ShipmentAssignedToDeliveryEvent` and an after-commit listener, so a rolled-back assignment pages nobody. The message carries the shipment code, order code and delivery slot — never the customer's full address, and never the handoff code.

**A local delivery can no longer be dispatched without a driver.** Enforced inside `ShipmentTransitionService`, under the same row lock as the transition, so it covers both routes into `out_for_delivery` (first dispatch and the retry from `delivery_failed`) rather than one controller. Returns **422** on `assigned_delivery_user_id`. Admin still owns dispatch; couriers have no dispatch, mark-ready, fail, reschedule, or slot permission.

**Dispatch mints a customer handoff code — and only a hash is ever stored.** A CSPRNG numeric code (length configurable, clamped 4–10, default 6) is generated inside the transaction that performs the transition, hashed onto the shipment, and handed to the customer by SMS. The plaintext is never written to the shipment, shipment history, a stored notification, a log, or any API response — admin, customer, and driver surfaces alike. Nothing about it derives from an id, phone number, public code, or the clock.

- **One SMS, not two.** The existing `ShipmentSentEvent` gained a transient `deliveryCode`; postal shipments keep the plain `shipment_sent` template while local delivery uses the new `shipment_sent_delivery_code` template. The stored in-app notification is unchanged and still contains no code.
- **Validity is scoped to the current attempt, not to a clock.** `out_for_delivery → delivery_failed` clears the hash immediately, so the code the customer is holding stops working; the next dispatch mints a fresh one and the previous code can never be replayed. Completion consumes the hash and stamps `delivery_verification_verified_at`.
- **Reassignment mid-delivery does not regenerate the code** — the customer is not asked to memorise a new number. The outgoing courier loses API access at once and the incoming one is notified.

**Completion requires the customer's code from everyone.** Driver and admin routes converge on one `MarkShipmentDeliveredAction`; only assignment authorization differs. An admin need not be the assignee but still needs the code — a code-free admin override would quietly undo the guarantee the code exists for. Verification and the transition share one transaction and one row lock, and everything downstream (`delivered_at`, history, order → completed, slot reservation, `ShipmentDeliveredEvent`) stays exactly where it already lived. Pickup and postal completion are untouched.

**A dedicated driver surface** at `GET /api/v1/delivery/shipments`, `GET /api/v1/delivery/shipments/{publicCode}`, and `POST /api/v1/delivery/shipments/{publicCode}/mark-delivered`. Couriers never touch `/admin/shipments`. Every lookup starts from a query scoped to `assigned_delivery_user_id` + `local_delivery`, so a shipment held by another driver is **404**, indistinguishable from one that never existed — a 403 would confirm it exists and turn the public-code space into an oracle. A focused resource carries the address snapshot, slot, customer name and phone, timestamps, and status; it carries no order totals, coupon or payment data, and no verification hash.

**Recovery and brute-force protection.** `POST /api/v1/admin/shipments/{publicCode}/resend-delivery-code` (local delivery, `out_for_delivery` only) mints a *new* code and retires the old one — with only a hash stored there is no old plaintext to recover, and that is the point. Resend is SMS-only and raises no second "shipment sent" in-app notification. A new `delivery-confirm` rate limiter keyed by *caller + shipment* guards both completion routes (default 5/min, `SHIPMENT_DELIVERY_CONFIRM_MAX_ATTEMPTS`): exhausting one delivery's budget cannot strand the rest of a round. Every wrong code returns the same generic 422 on `code` — never a hint that it was stale, expired, or nearly right.

**Address snapshots now carry the map pin.** `EloquentShipmentManager` reads the checkout address through the new `IdentityManagerInterface::getOwnedAddressSnapshot()` + `AddressSnapshotDTO` instead of querying Identity's tables directly, and the frozen snapshot gained `latitude`, `longitude`, and `map_address` so a courier navigates to the pin rather than parsing a street line. Historical orders and shipments are never rewritten, and editing an address later never moves an already-placed delivery.

**Permissions:** `profile.create-any`, `profile.assign-delivery` (Identity) and `shipment.delivery.{assign,view-assigned,complete-assigned,resend-code}` (Shipment). `delivery` receives `view-assigned` + `complete-assigned` only; `admin` receives the rest plus every existing shipment permission, unchanged.

**Migrations:** `shipments` gains `assigned_delivery_user_id` (indexed, loose Identity reference), `delivery_assigned_at`, `delivery_verification_code_hash`, `delivery_verification_issued_at`, `delivery_verification_verified_at`; new `shipment_delivery_assignments` table (same-module FK on `shipment_id`, loose Identity ids). No cross-module foreign keys. Verified on SQLite via `migrate:fresh --seed`.

**Seeders** create a demo courier holding `customer + delivery` and run demo local deliveries through the *real* business path — assignment via `AssignShipmentDeliveryAction`, completion via `MarkShipmentDeliveredAction` with the real code, overheard from the dispatch event exactly as a customer reads it off their SMS. Nothing bypasses the new invariants to make seeding pass.

**Tests:** 47 new tests across `DeliveryRoleTest`, `DeliveryAssignmentTest`, `DeliveryWorkerApiTest`, and `DeliveryVerificationCodeTest`, plus updates to the existing local-delivery workflow and notification tests. Suite: **688 tests**, with the one pre-existing unrelated `ProfileTest::authenticated_user_can_update_profile` failure unchanged.

### Feature — Promotion module: automatic discounts, coupons, and campaigns

**New bounded context `Modules/Promotion/`** (provider registered in `bootstrap/providers.php` *before* Catalog, since Catalog resolves it for live pricing). Promotion imports no Catalog/Cart/Order/Payment model, contract, or DTO — everything it needs arrives as primitives or its own DTOs, so the dependency arrow stays one-way and no cycle forms.

**Pricing model replaced.** `product_variants.compare_at_price` is **dropped**. Catalog now stores only `base_price` (the regular price); the applicable promotional price is computed live by Promotion on every read. A price column could not express a sale spanning many products, a schedule, an instant off switch, a cap, or two overlapping sales — all of which are now first-class.

- Removed from the create/nested-create/update/variant-upsert requests, `CreateProductVariantAction`, `UpdateProductVariantAction`, `ProductVariant`, `ProductVariantDTO`, `ProductVariantResource`, `CartItemDTO`, `CartItemResource`, and the Catalog sample seeder. Sending the field is now silently ignored rather than stored.
- **`order_items.compare_at_price` is deliberately kept** and still renders for orders placed before this change. New orders always write `null`. Historical orders are financial records and are never rewritten or backfilled.

**Automatic discounts — one winner, never a stack.** A variant may match a variant rule, several product rules, several category rules (including ones aimed at an ancestor), and several brand rules at once. Every candidate is costed in rials and only the largest actual reduction applies. Specificity does **not** override savings — a 30% product rule beats a 20% variant rule. Exact ties break deterministically: most specific target (variant → product → category → brand), then higher `priority`, then lowest discount id, so the snapshot stored on an order is reproducible forever.

- **Integer basis points only.** 20% is `2000`, 12.5% is `1250`, 100% is `10000`. `DiscountCalculator` multiplies before dividing (`intdiv($amount * $bps, 10000)`) so small amounts are not truncated to zero, floors consistently so a cart total and an order total can never disagree by a rial, and clamps to `[0, amount]` so a price can never go negative. No PHP float touches a price.
- **Batched evaluation.** `evaluateAutomaticDiscounts()` is batch-only; `EloquentCatalogManager` prices a whole page in one call alongside the existing media/stock batching. A regression test asserts a 10-product page costs no more Promotion queries than a 1-product page.
- **Category ancestry is Catalog's job.** New `Modules/Catalog/Domain/Services/CategoryHierarchy` (scoped binding) loads `id → parent_id` once per request and walks it both ways: upwards for the pricing context, downwards for `has_discount` and campaign product lists. Promotion never traverses the Catalog tree.
- **Targets are loose references with no FK into Catalog**, and are never validated for existence — doing so would require Promotion to call Catalog, closing the cycle. A target naming a deleted row simply becomes inert.

**`has_discount` reimplemented.** It now means "at least one variant currently has an applicable active automatic discount", compiled into a SQL constraint on Catalog's own tables from the target ids Promotion publishes — so `total`, `last_page`, and later pages stay correct. The constraint carries explicit `IS NOT NULL` guards because `NULL IN (…)` is `NULL` in SQL, which would otherwise drop every uncategorized, brandless product from a `has_discount=false` page. `min_price`, `max_price`, `sort=cheapest`, and `sort=most_expensive` **keep their existing base-price semantics**.

**Cart.** Lines are charged at the effective price. `CartItemResource` gains `effective_price`, `automatic_discount`, `regular_line_total`, and `automatic_discount_amount`; `CartResource` gains `regular_total_price` and `automatic_discount_total`. Cart still talks only to Catalog, never to Promotion, and stores nothing promotional — so a discount ending between two page loads is reflected immediately. **No coupon state is stored on a cart.**

**Order.** Checkout re-reads Catalog and snapshots the winner: new `order_items.regular_price_per_unit`, `automatic_discount_amount_per_unit`, and `automatic_discount_snapshot` (self-contained, so a historical line stays explainable after the rule is edited or deleted). `price_per_unit` is now the effective price and `line_total` follows it.

**Coupons.** A coupon is a marketing code (`SUMMER10`) activating a dormant `trigger_type=coupon`, `scope=all` rule. `scope=all` does **not** mean a store-wide sale — it means "applies to the whole post-automatic merchandise subtotal *once a code activates it*". Coupon rules may carry no targets; automatic rules must be `targeted` with at least one target and reject the coupon-only `min_subtotal`. Codes are trimmed/uppercased, restricted to `A–Z 0–9 - _`, uniquely indexed, and immutable once redeemed.

- One coupon per order, stacking **after** automatic pricing (20% then 10% gives 72, not 70). Shipping and tax are excluded from both the `min_subtotal` test and the calculation.
- **Never allocated across items** — stored once at order level. New `orders.coupon_code`, `coupon_discount_amount`, `coupon_snapshot`, `payment_pricing_finalized_at`.
- New advisory `POST /api/v1/orders/{order}/coupon/check` (auth + ownership, no promotion permission). Reserves nothing, mutates nothing.
- **The first payment initialization freezes pricing**, including the decision to use *no* coupon. Retrying with the same code (in any case) or omitting it is allowed; a different code returns 422. `POST /payments/initialize` accepts only `coupon_code` — never an amount, total, or percentage.
- **Order, not Payment, owns the money.** New `OrderManagerInterface::finalizeForPayment()`; Payment charges the frozen `order.total_amount` and runs no pricing logic. A gateway failure leaves the freeze and the reservation intact so the retry charges the identical figure.
- **Concurrency-safe reservation:** the coupon row is locked `FOR UPDATE` *before* counting, then limits are checked and the reservation written; a unique `coupon_redemptions.order_id` is the backstop and enforces one-coupon-per-order. Limits count `reserved + redeemed`, never `released`.
- **Lifecycle hangs off the existing shared primitives** rather than being duplicated: release in `CancelOrderAction::releaseAndCancel()` (customer cancel, admin cancel, TTL expiry, pending-order replacement) and redemption in `EloquentOrderManager::markAsPaid()` (online capture and in-person cash alike). Both idempotent. A failed payment deliberately does **not** release.

**Campaigns.** A merchandising grouping, not a pricing rule: one campaign links many *different* automatic discounts, so its products can each carry a different discount. A campaign never forces its own rule to win — a product also matching a stronger unrelated rule shows that stronger price. Coupon-backed rules cannot be linked. Storefront endpoints live in **Catalog** (`GET /catalog/campaigns`, `/campaigns/{slug}`, `/campaigns/{slug}/products`) because resolving products needs Catalog's tables and visibility rules; Promotion publishes only metadata and raw target ids.

**Admin API + permissions.** `/api/v1/admin/promotions/{discounts,coupons,campaigns,redemptions}` behind `auth:sanctum` + `throttle:api`. Six new permissions seeded to `admin` and to no customer: `promotion.view-admin`, `promotion.create`, `promotion.update`, `promotion.delete`, `promotion.coupon.manage`, `promotion.campaign.manage`. Coupon/campaign mutations are split from discount CRUD so a marketing operator can manage codes and merchandising without rewriting pricing rules. Deletes are soft-deletes — redemption history and order snapshots are never destroyed.

**Migrations:** `discounts`, `discount_targets`, `coupons`, `coupon_redemptions`, `campaigns`, `campaign_discount`; drop `product_variants.compare_at_price`; add the OrderItem snapshot and Order coupon columns. No cross-module foreign keys. Verified on SQLite via `migrate:fresh --seed`.

**Docs:** new `DISCOUNT_ARCHITECTURE.html` explains the pricing model, winner algorithm, tie-breaking, integer math, coupon lifecycle, freeze semantics, campaigns, schema, API, and permissions.

**Tests:** 120 new tests, all green — `tests/Unit/Promotion/` (integer math, winner selection, tie-breaking) and `tests/Feature/Promotion/` (targeting and ancestry, `has_discount` pagination, base-price filter/sort preservation, cart and order pricing, the full coupon lifecycle including limits and idempotency, campaigns, and the six-permission matrix). Suite: **641 passed**, with the one pre-existing unrelated `ProfileTest` failure unchanged.

### Fix — customer Address detail resolves public code

- Changed only customer `GET /api/v1/addresses/{publicCode}` to normalize and exactly resolve the existing `bda-XXXXXX` identifier. Numeric/malformed/missing codes return 404; existing owner/view-any authorization remains unchanged. Address mutations, checkout `address_id`, Shipment eligibility, internal contracts, and admin Address routes continue to use numeric ids.

### Feature — customer Shipment method eligibility and delivery-slot sorting

- `GET /api/v1/shipment/delivery-slots` now validates `sort` (`date`, `starts_at`, `remaining_capacity`, `capacity`, `created_at`) and `direction` (`asc`, `desc`). Its customer default is chronological (`date ASC`, then `starts_at ASC`), all sorts have deterministic secondary/id ordering, and remaining-capacity sorting reuses the existing capacity/reservation calculation.
- `GET /api/v1/shipment/methods` now treats an omitted address as a valid pickup-only state by returning canonical configured methods with `requires_address: false` (currently `in_person_pickup`). A valid owned address preserves normal eligibility and pickup availability; explicitly invalid/foreign addresses remain 422, and checkout validation for address-required methods is unchanged.

### Feature — immutable OrderItem compare-at and primary-image snapshots

- Added nullable integer `order_items.compare_at_price` through an additive migration with no backfill: existing rows remain null. Checkout now snapshots `CartItemDTO::compareAtPrice` alongside the selling `price_per_unit`, while all line/order/payment totals continue to use only the selling base price.
- `product_snapshot` now also stores `primary_image_url` from the enriched Cart item. `image_url` remains the purchased variant image; `primary_image_url` is the parent product primary image. Both values are immutable, no Catalog refresh/backfill occurs, and legacy snapshots without the new JSON key remain valid.
- The shared `OrderItemResource` exposes `compare_at_price` to customer and admin Order responses. Sample Order data and focused checkout/detail/admin tests cover distinct image URLs, nullable compare-at values, total invariance, and later Catalog changes.

### Feature — customer Order detail by owned public code

- Added authenticated, rate-limited `GET /api/v1/orders/{publicCode}`. The normalized Order code and authenticated user ID are matched in one exact query; missing and foreign-owned codes both return 404. The unwrapped response preserves the existing customer Order/item shape and adds all customer-safe Payment attempts (newest first) plus the complete live Shipment and history, or `null` before activation. Cross-module reads use Contracts + DTOs only; raw gateway responses remain hidden and existing endpoints are unchanged.

### Fix — demo seeders: give the demo customers addresses the fulfillment rules can actually accept

- **`php artisan migrate:fresh --seed` aborted** with `Local delivery is not available for this address.` from `EloquentShipmentManager::validateSelection()` whenever a service area was configured (`SHIPMENT_LOCAL_DELIVERY_PROVINCE_IDS` / `SHIPMENT_LOCAL_DELIVERY_CITY_IDS`). `OrderSampleDataSeeder` created each demo address with a null `province_id`/`city_id`, so `ConfigLocalDeliveryEligibility::isEligible(null, null)` rejected every `local_delivery` blueprint. It went unnoticed because an unconfigured service area (the `.env.example` default) makes eligibility permissive.
- **One address per customer could never have worked.** The two rules are mutually exclusive by location: local delivery is offered only *inside* the service area, and postal is withdrawn *there*. Demo customers now get **two** addresses — `Home` inside the area (default shipping) and `Work` outside it — and `seedOrder()` picks by method type, so postal orders ship from `Work` and local-delivery/pickup orders from `Home`.
- Both locations are **derived from `config('shipment.local_delivery')`, never hard-coded**, so re-pointing the service area reseeds correctly. With no area configured any city is used (everything is eligible anyway). If the configured ids match no seeded city the seeder now fails with an explicit message naming the env keys, instead of the opaque validation error above. When every seeded city is inside the area, the out-of-area address falls back to a location-less one — which is never treated as inside, so postal still seeds.
- Seeding is otherwise unchanged: orders, inventory reservations, slot holds, payments, and shipments all still run through the real contracts. Verified end to end — 19 orders, 18 payments, 16 shipments, 28 status transitions.
- **The test suite had the same leak.** `SHIPMENT_LOCAL_DELIVERY_*` was unset in `phpunit.xml`, so a developer with a service area in their `.env` saw 12 Shipment/Order tests fail on the identical message — the factories build addresses outside the configured area. Both keys are now pinned empty in `phpunit.xml` (matching `.env.example`), so the suite is hermetic; `LocalDeliveryServiceAreaTest` and `ShipmentMethodsTest` keep setting their own area via `Config::set()`.

### Feature — consistent customer-facing public codes across seven entities

**Everything a customer might read aloud, quote to support, or paste into a search box now carries a short code in one shape: `<namespace><segment>-<SUFFIX>`, e.g. `bdo-Q8M2XC`.** Purely additive — no primary key, foreign key, relation, cron input, internal query, or authorization rule changed.

#### Added
- **`App\Support\PublicCodeGenerator`** — the single place a code is assembled. Reads the namespace from config, takes an entity segment, and emits six CSPRNG characters from a human-safe alphabet: `23456789ABCDEFGHJKMNPQRSTUVWXYZ`. `0`/`O` and `1`/`I`/`L` are excluded so a code read over the phone cannot land on a different record. The suffix is **not** derived from the primary key, a hash, a timestamp, or a sequence. The class performs no queries and imports no business-module model; uniqueness is the owning module's job, supplied as a callback to `generateUnique()`.
- **`App\Support\PublicCodeEntity`** — the one mapping of entity → segment (`p`/`v`/`o`/`t`/`s`/`a`/`c`). Full prefixes like `bdp` and `bdo` are never written as string literals anywhere else. Payment is `t`, because `p` already belongs to Product.
- **`App\Support\HasPublicCode`** — model-side wiring: a `creating` hook, `generateUniquePublicCode()` (checks **only** the model's own table), and `createWithPublicCode()`. The latter assigns the code itself rather than relying on the hook, because the hook is muted wherever model events are disabled — seeders run that way.
- **`config/public_codes.php`** + required env key **`PUBLIC_CODE_NAMESPACE=bd`** (added to `.env.example` and `phpunit.xml`). Deliberately no fallback: a missing or malformed value raises `PublicCodeGenerationException` rather than silently minting codes under the wrong prefix, and a public identifier cannot be un-issued. Normalized to lowercase. The suffix length and alphabet stay as class constants, not config — widening either would make already-issued codes ambiguous against new ones.
- New columns, each nullable → backfilled in 500-row chunks → unique-indexed, in their owning module's migration: `orders.public_code`, `payments.public_code`, `addresses.public_code`, `categories.public_code`. Backfills use the shared generator directly against their own table (model events are unavailable in migrations).

| Entity | Format | Column | Status |
|---|---|---|---|
| Product | `bdp-XXXXXX` | `products.uuid` | existing column reused |
| Product variant | `bdv-XXXXXX` | `product_variants.sku` | existing column reused |
| Order | `bdo-XXXXXX` | `orders.public_code` | **new** |
| Payment | `bdt-XXXXXX` | `payments.public_code` | **new** |
| Shipment | `bds-XXXXXX` | `shipments.public_code` | existing column reused |
| Address | `bda-XXXXXX` | `addresses.public_code` | **new** |
| Category | `bdc-XXXXXX` | `categories.public_code` | **new** |

#### Changed
- **Product codes** now come from the shared generator (`Product::generateUniquePublicCode()`; `generateUniqueUuid()` is kept as a compatibility wrapper for existing callers and seeders). The `uuid` column is reused — no second product identifier was added.
- **Variant SKUs have one source of truth.** The positional `'bdp'.$productId.'-v'.$n` formulas in `CreateProductAction`, `CreateProductVariantAction`, and `UpdateProductAction` are gone; `EloquentCatalogManager::createProductVariant()` mints the SKU and **discards any inbound `sku`**, so no caller — action, controller, or module — can choose one. `updateProductVariant()` likewise strips `sku`: Inventory, Cart, order items, and reservations all key off the existing value, so a SKU is never regenerated.
- **Shipment codes** switch from `SH-<10 random>` to `bds-XXXXXX` via the same generator. `activateForPaidOrder()` now uses `createWithPublicCode()`, so a public-code collision is retried internally and the existing `catch` only ever sees the duplicate-`order_id` case it was written for.
- **Product routes** accept both formats: the constraint is built by `PublicCodeGenerator::routePattern()` as `(legacy-hex | bdp-XXXXXX)`. Neither branch can match `admin` or `slug`, so reserved literal routes are still never shadowed. (Route caching bakes the namespace in — run `route:clear` after changing it.) Shipment routes already used `[A-Za-z0-9\-]+`, which spans both generations; that is now documented so nobody narrows it.
- **`orderPublicCode` added to the integration events** `OrderPaidEvent`, `OrderCancelledEvent`, `PaymentFailedEvent`, `Shipment{PreparingStarted,Sent,Delivered}Event` — **alongside**, never replacing, the numeric `orderId`, which listeners still need for in-app deep links. Events remain primitives-only. Shipment resolves the order code through `OrderManagerInterface::findOrder()`, never the Order model.
- **Customer-facing copy now quotes codes, not internal ids.** SMS and the admin "سفارش شماره … پرداخت شد" message carry `bdo-…`; notification `data` gains `order_public_code` beside the retained numeric `order_id`. The SMS **parameter name** stays `OrderId` — that name is the provider-side template variable, so renaming it would break every configured SMS.ir template.
- **Payment result page** now shows کد سفارش and کد پرداخت beside the existing شماره پیگیری. The gateway reference is kept for provider-side reconciliation; callback security, verification, authority handling, and idempotency are untouched.
- `POST /payments/initialize` keeps `payment_id` and adds `payment_public_code` + `order_public_code`. Prefixed names because that response is a plain object carrying two entities — a bare `public_code` would not say which one it named. Payment *resources* expose the unambiguous `public_code`.
- `CatalogSampleDataSeeder`, `CategoryTreeSeeder`, `OrderSampleDataSeeder`, `PaymentSampleDataSeeder` and `AddressFactory` assign codes explicitly. **This also fixes a latent bug:** `CatalogSampleDataSeeder` passed `'uuid'` through `firstOrCreate`, but `uuid` is not mass-assignable and the `creating` hook is muted under `WithoutModelEvents` — so seeded products were silently getting a **null** public code.

#### Search
- `GET /catalog/products` (and `/categories/{id}/products`): `bdp-XXXXXX` → exact match on `products.uuid`; `bdv-XXXXXX` → exact match on `product_variants.sku`, returning the owning product. Any other term keeps the existing contains-match on title, description and brand. Recognizable codes never use `LIKE`. **Not** applied to the admin product list, which keeps its substring search over slug and SKU.
- `GET /catalog/categories/roots` gains `search`: an exact `bdc-XXXXXX` resolves one category **at any depth** (a customer holding a code has no idea whether it is a root); any other term is a contains-match on name or slug. With no term the endpoint returns the root menu exactly as before.
- `GET /orders` and `GET /addresses` gain `search`: exact match on the respective `public_code`, **always intersected with the caller's own rows**. Another customer's real code returns an empty page byte-for-byte identical to a nonexistent code, so neither endpoint can be used to probe for other people's records. Ordering and pagination are unchanged when no term is supplied. Deliberately **not** added to the admin order or admin address endpoints.
- All search input is trimmed and case-normalized (`BDO-q8m2xc` → `bdo-Q8M2XC`). Partial codes never match — matching is whole-string equality on the unique index, so records cannot be enumerated by prefix.

#### Compatibility
- **Nothing was rewritten.** Existing 7-character hex product UUIDs, existing variant SKUs, and existing `SH-*` shipment codes are untouched and keep resolving on every route. Only newly created records use the new format, so existing product URLs and bookmarks survive.
- Changing `PUBLIC_CODE_NAMESPACE` later affects **new** records only; stored codes keep the namespace they were issued under.
- Codes are never accepted from client input, never appear as writable fields, are never regenerated on update, and are never reused after deletion.
- Numeric ids remain the identifier for foreign keys, order items, payment initialization, shipment relationships, module contracts, admin filters, cancellation logic, scheduled jobs, and integration-event lookups.

#### Notes / trade-offs
- The four new columns are **nullable at the database level**, matching the precedent set by `products.uuid`. Tightening to `NOT NULL` on SQLite (used for tests and local development) rebuilds the table and can silently drop existing indexes such as `orders[user_id, status]`. The application layer guarantees a value on every insert, and the unique index is the real integrity backstop.
- `createWithPublicCode()` recognizes a duplicate-key violation **specifically on the code column** (SQLSTATE 23000/23505 *and* the column name *and* a unique/duplicate signal) and retries with a fresh candidate. Every other `QueryException` propagates untouched — a duplicate slug or a foreign-key failure is never mistaken for a code collision or swallowed.

#### Tests
- New `tests/Unit/Support/PublicCodeGeneratorTest.php` (12): per-entity prefixes and six-character suffixes, no ambiguous characters across 400 samples, randomness (not sequential/derived), lowercase namespace normalization, missing/invalid namespace failing loudly, unknown segment rejected, input normalization, whole-string entity-specific matching, retry-past-taken-candidates, exhaustion throwing, and the dual-format route pattern excluding `admin`/`slug`.
- New `tests/Feature/PublicCode/PublicCodeTest.php` (26): a code per entity, uniqueness, client-supplied identifiers ignored on create and update, namespace change affecting only new records, legacy hex product URLs and legacy `SH-*` shipment routes still resolving, product-by-code and product-by-variant-SKU search, ordinary title search preserved, order and address search ownership isolation (including the indistinguishable-empty-result proof), exact category-code search plus partial-code rejection, unique indexes present on all seven columns, the database rejecting a duplicate when the application check is bypassed, seeders assigning codes with model events disabled, and numeric ids / foreign keys / SKUs still intact.
- Updated existing assertions for the new formats in `ProductsTest`, `ProductVariantsTest`, `ShipmentNotificationTest`, `NotificationIntegrationTest`.
- Full suite: **506 passing**, with one pre-existing unrelated `ProfileTest` failure (present on the unmodified baseline).

### Change — Cart: richer flattened Catalog display data

- Cart item responses now expose the selected variant's `type`, `attributes`, and existing variant `image_url`, plus the owning product's `primary_image_url`.
- The enrichment stays flattened and continues through Catalog DTOs and batch media hydration; Cart does not reuse `ProductVariantResource` or query Catalog/Media models directly.

### Feature — Shipment: env-backed method prices and a local-delivery service area that withdraws post

- **Method prices are env-backed.** `SHIPMENT_POST_STANDARD_PRICE`, `SHIPMENT_POST_EXPRESS_PRICE`, `SHIPMENT_LOCAL_DELIVERY_PRICE`, `SHIPMENT_PICKUP_PRICE` in `config/shipment.php`, each keeping the previous value as its fallback. Cast with `(int)` — `env()` returns strings, and an uncast price would reach `ShipmentSelectionDTO::$shippingCost` and `orders.shipping_cost` as a string, breaking the Cents Rule. Values are integer rials: no decimals, no separators. Repricing affects new checkouts only; existing orders keep their frozen `shipment_snapshot` and shipments their `shipping_cost`.
- **Local-delivery service area is now actually configurable.** `config('shipment.local_delivery.province_ids'|'city_ids')` ← `SHIPMENT_LOCAL_DELIVERY_PROVINCE_IDS` / `SHIPMENT_LOCAL_DELIVERY_CITY_IDS`. **These config keys were read by `ConfigLocalDeliveryEligibility` but had never been defined**, so eligibility silently passed every address; they exist now. Comma-separated values are parsed to a deduped `list<int>` with blank/non-numeric/≤0 entries dropped — ints are required because `isEligible()` compares with `in_array(..., true)`. An address matches if **either** list contains it, so provinces give a broad zone and cities add exceptions outside it. Set cities alone for the common single-city store.
- **Inside the service area both postal methods are withdrawn.** `getAvailableMethods()` returns `post_standard`/`post_express` as `available:false` with a reason, and `validateSelection()` rejects them with **422** on `shipment_method_code` — hiding a method without enforcing it would leave the rule bypassable by any client that skips the method list. In-store pickup is never affected, and a null address is never treated as inside.
- `LocalDeliveryEligibilityInterface` gains **`hasServiceArea(): bool`**. This is load-bearing, not cosmetic: with no area configured `isEligible()` is permissive for *everyone*, so a naive "postal is off wherever local delivery is on" would have disabled post for every customer in the country. The exclusion gates on `hasServiceArea()` first, leaving an unconfigured store's behavior completely unchanged.
- Tests: +9 (`LocalDeliveryServiceAreaTest`) — postal withdrawn inside the area, local delivery and pickup unaffected, postal available outside, province-only areas, the unconfigured-store regression, no address selected, checkout 422 for both postal codes with no order written, and checkout accepted for post outside / pickup inside.

### Feature — Shipment: default working hours, full demo fulfillment data, and an on-demand slot-generation endpoint

- **`POST /api/v1/admin/shipment/delivery-slots/generate`** (`auth:sanctum` + `shipment.slot.manage`, `throttle:api`) triggers the same idempotent generation the nightly `shipment:generate-delivery-slots` command runs — for local development and any environment without cron. Optional `days` (integer 1–90, whole-number strings accepted from form-encoded requests); omitted falls back to `config('shipment.delivery.generation_days')`. Responds `{"data":{"created":N,"days":N}}`. The literal `generate` segment is declared before the `{slot}` routes so it is never parsed as a slot id. Guests get **401**, a user without the permission gets **403** before validation.
- **`ShipmentScheduleSeeder`** seeds the **default** recurring working periods — Saturday–Thursday, 09:00–13:00 and 16:00–21:00, Friday closed (weekday numbers follow `Carbon::dayOfWeek`, matching `DeliverySlotGenerator`) — then generates the first batch of sessions. These are starting values the admin owns and edits via `/admin/shipment/delivery-working-periods`: every row uses `firstOrCreate` keyed on `(weekday, starts_at, ends_at)`, so re-seeding never duplicates a period and never flips `is_active` back on for one an admin deactivated.
- **`ShipmentSampleDataSeeder`** creates one operational shipment per paid demo order and drives it to a target state, covering **all 16 states across the three workflows** — postal (`pending`, `preparing`, `ready_for_post`, `handed_to_post`, `cancelled`), local delivery (the same plus `ready_for_dispatch`, `out_for_delivery`, `delivered`, `delivery_failed`), and pickup (`pending`, `preparing`, `ready_for_pickup`, `picked_up`). Nothing is inserted by hand: shipments are created through `ShipmentManagerInterface::activateForPaidOrder()` (the call `markAsPaid` makes) and advanced through `ShipmentTransitionService`, with the route discovered breadth-first from the workflow's **own** transition map — so a workflow change reshapes the demo data instead of breaking it. Result: real status histories, correctly synced order statuses, settled slot reservations, and the customer notifications the transitions genuinely produce. Terminal states carry realistic operator input (per-shipment `tracking_number`, `failure_reason`, `receiver_name`).
- **`OrderSampleDataSeeder`** now gives every demo order a real fulfillment selection, resolved through `ShipmentManagerInterface::validateSelection()` exactly like checkout (address ownership, local-delivery eligibility, slot bookability), and persists `shipment_method_code`, `shipment_snapshot`, and a non-zero `shipping_cost` folded into `total_amount`. Local-delivery orders book a genuine session via `holdForPendingOrder()`, round-robined across bookable slots so no single session is overbooked; terminal unpaid orders release the hold. 9 → 19 orders. Order status is left at `paid` — the **shipment transitions** are what move it to processing/shipped/completed, as in production.
- `DatabaseSeeder` ordering: `ShipmentScheduleSeeder` runs before `OrderSampleDataSeeder` (orders need bookable slots), and `ShipmentSampleDataSeeder` runs last.
- Removed a leftover `$this->info("days are …")` debug line from `ShipmentGenerateDeliverySlotsCommand`.
- Tests: +7 (`GenerateDeliverySlotsApiTest`) — generation for an explicit window, config fallback, re-run creates nothing and preserves an operator's capacity/closed edits, `days` bounds validation, form-encoded string cast, 401 guest, 403 without `shipment.slot.manage`. Shipment suite 65 → 72.

### Feature — Transactional notifications wired into the business flows (events + listeners)

- Business modules now publish **integration events** carrying primitives only (ids, strings, numbers — never an Order/User/Payment/Shipment model), and the Notification module listens. No controller, callback, service, or API resource calls `NotificationManagerInterface`, and nothing outside `Modules/Sms` touches an SMS provider.
- New events: `Order\Domain\Events\OrderPaidEvent` (orderId, userId, totalAmount), `Order\Domain\Events\OrderCancelledEvent` (orderId, userId), `Payment\Domain\Events\PaymentFailedEvent` (orderId, userId), `Shipment\Domain\Events\{ShipmentPreparingStartedEvent, ShipmentSentEvent (+trackingCode), ShipmentDeliveredEvent}`.
- New listeners in `Notification\Application\Listeners` — `SendOrderPaidNotifications`, `SendPaymentFailedNotification`, `SendOrderCancelledNotifications`, `SendShipmentPreparingNotification`, `SendShipmentSentNotifications`, `SendShipmentDeliveredNotifications` — registered in `NotificationServiceProvider::boot()`. **All implement `ShouldHandleEventsAfterCommit`**, so a rolled-back business transaction notifies nobody.
- **OrderPaid timing + idempotency.** Dispatched inside `EloquentOrderManager::markAsPaid` *after* the status change, inventory commit, and shipment activation, and only on the real transition — the existing already-paid early return means a repeated gateway callback never reaches the dispatch, so duplicate callbacks cannot produce duplicate notifications. Because the listeners are after-commit, nothing is sent if the inventory commit or shipment activation throws.
- **PaymentFailed** is dispatched only from the server-side verification-failure branch of `HandleZarinpalCallbackAction` — not when a customer merely abandons the gateway page. The owning user is resolved through `OrderManagerInterface::findOrder()`; no User model crosses the boundary.
- **OrderCancelled** is dispatched from `CancelOrderAction::handle` (customer) and `AdminCancelOrderAction::handle` (operator) — deliberately **not** from the shared `releaseAndCancel` primitive, which checkout also uses to retire a superseded pending order the customer never asked about, and **not** from TTL expiry (see below).
- **Shipment events ride the existing single transition primitive.** `ShipmentTransitionService::announce()` maps already-existing statuses to events: `preparing`, `handed_to_post`/`out_for_delivery` (the two the module already maps to the order status `shipped`), and `delivered`. No new status, workflow, or column was introduced. `picked_up` is intentionally silent.
- Notification content: new `NotificationType` enum (payment_success, payment_failed, order_cancelled, shipment_preparing, shipment_sent, shipment_delivered, admin_order_paid). Copy lives in the listener for each flow; there is no template-management system, and no email/push/marketing channel was added.
- Admin fan-out: `IdentityManagerInterface` gains `getAdminUserIds(): list<int>` (ids only) so `SendOrderPaidNotifications` can raise an in-app-only "سفارش پرداخت شد" notification for every admin.
- SMS stays optional throughout: a missing provider template id still skips (logged, `skipped` delivery row) and never throws, never fails the order/shipment flow, and never rolls back a transaction.
- Tests: +16. `NotificationIntegrationTest` (11) covers OrderPaid dispatch/customer in-app/SMS parameters/missing-template-does-not-break-payment/duplicate-callback-no-duplicate-notification/admin notification, PaymentFailed dispatch + in-app-without-SMS, OrderCancelled dispatch + notification + SMS, and the silent internal release primitive. `ShipmentNotificationTest` (5) covers preparing (SMS only), hand-to-post with `TrackingCode`, local dispatch without one, delivered, and an unconfigured template not breaking the transition. Full suite: 448 passing.

### Change — Notification/Sms: SMS templates are optional (skip instead of fail)

- **SMS sending is never mandatory.** When the active provider has no template id configured for a notification template — or no credentials at all — the send is now **skipped**: no exception, no HTTP call, an `info` log with the reason, and an `SmsResultDTO::skipped()`. The parent business operation is unaffected either way.
- `SmsResultDTO` gains a third outcome (`skipped`, alongside `success`/`failure`) via `SmsResultDTO::skipped(provider, reason)`. `failure` is now reserved for **real attempts that did not succeed** (transport error, provider rejection), so an unconfigured template never looks like a delivery incident in the audit trail or in alerting.
- `DeliveryStatus` gains `skipped`. `SmsChannel` maps result → status (`success` → `sent`, `skipped` → `skipped`, else `failed`) and writes the reason into `notification_deliveries.error` with neither `sent_at` nor `failed_at` set. **Behavior change:** a recipient with no phone number on file is now recorded as `skipped` rather than `failed` — it is data absence, not a system fault. Requesting the SMS channel with no payload at all remains `failed` (caller misuse, not configuration).
- New `NotificationTemplate` enum (`payment_success`, `order_cancelled`, `shipment_preparing`, `shipment_sent`, `shipment_delivered`): business template names are now **internal constants**, and `SmsPayloadDTO::$template` takes the enum instead of a raw string. Provider template ids remain configuration (`SMS_SMSIR_*_TEMPLATE_ID`); `SmsChannel` passes only the enum's value across the module wall, so the Sms module still depends on nothing from Notification. Adding a case without configuring an id is safe — that message is simply not sent.
- `FakeSmsProvider` gains `shouldSkip`/`skipReason` so the skip path is testable at the notification level.
- Tests: 25 → 31. New — configured template sends (`a_configured_template_is_sent`), missing template id skips without throwing or calling the provider, missing template never throws through the manager, missing credentials skip rather than fail, a skip is not a failure while a rejection is, an unconfigured template skips the SMS but still stores the in-app notification, a skipped SMS is never recorded as a failure. Updated — the no-phone case now asserts `skipped`. The failed-provider case still asserts a `failed` delivery record with `failed_at` set.

### Feature — Notification + Sms: reusable notification infrastructure (no business events yet)

- New **`Modules/Notification`**: in-app notification storage plus a channel abstraction. `NotificationManagerInterface` (`send(NotificationRequestDTO): ?NotificationDTO`, `getUserNotifications`, `markAsRead`, `unreadCount`) is the only public surface; `NotificationChannelInterface` is implemented by `DatabaseChannel` (the sole writer of `notifications`) and `SmsChannel`, resolved through `NotificationChannelFactory`. No `EmailChannel`/`PushChannel`. The module holds **no business copy and no event policy** — the caller supplies type, title, message, `data`, and the channel list.
- New **`Modules/Sms`**, deliberately separate from Identity's OTP delivery: `SmsManagerInterface` → `SmsProviderFactory` → `SmsProviderInterface`, with `SmsIrProvider`, `LogSmsProvider` (dev default), and `FakeSmsProvider` (tests, container singleton). Notification depends on `SmsManagerInterface` only and never names a provider. Identity's `OtpSenderInterface`/`SmsIrOtpSender` is untouched and unreused — same vendor, different responsibility.
- **Stable internal SMS format:** `SmsMessageDTO { receiver: "09121234567", template: "payment_success", parameters: {"OrderId": 123} }`. Template names and business parameter names belong to our system and never vary by provider; each provider translates (SMS.ir → `{mobile: "989121234567", templateId: 424242, parameters: [{name, value}]}`).
- **Schema:** `notifications` (`user_id` plain reference — no FK/join into Identity — `type`, `title`, `message`, JSON `data`, nullable `read_at`, timestamps, indexed on `[user_id, created_at]` and `[user_id, read_at]`) and `notification_deliveries` (`notification_id`, `channel`, `status`, `provider`, `provider_reference`, `sent_at`, `failed_at`, `error`, timestamps). **Design note:** `notification_id` is nullable because SMS-only notifications (e.g. "shipment preparing") intentionally create no in-app row; the delivery audit still records the attempt.
- **Failure isolation:** SMS is best-effort. A provider outage, an unknown configured provider, a recipient without a phone, or a missing SMS payload all produce a *failed* delivery record with the error — never an exception into the caller, so a payment or shipment can never be rolled back by SMS. In-app persistence happens first so external deliveries can attach to it.
- **API:** `GET /api/v1/notifications` (paginated `{data, links, meta}`, scoped to the caller) and `POST /api/v1/notifications/{notification}/read`, both `auth:sanctum` + `throttle:api`. `NotificationPolicy` (permission-based, typehinted `Authorizable&Authenticatable`) enforces ownership — another user's notification is 403, never readable or mutable. `NotificationResource` exposes `id/type/title/message/data/read_at/created_at` only; provider names, provider references, and error strings are never serialized.
- **Config:** new `config/sms.php` (`sms.default`, `sms.providers.smsir.{api_key, endpoint, templates.*}`) with new `.env.example` keys `SMS_PROVIDER`, `SMS_SMSIR_API_KEY`, `SMS_SMSIR_ENDPOINT`, and one `SMS_SMSIR_*_TEMPLATE_ID` per template. Template *ids* are provider-specific and configured; template *names* and parameter names are ours and never appear in env.
- **Wiring:** `SmsServiceProvider` + `NotificationServiceProvider` registered in `bootstrap/providers.php`; `NotificationPermissionsSeeder` (`notification.view-own`, `notification.mark-read-own` → admin + customer) added to `DatabaseSeeder`; `Tests\TestCase::seedNotificationPermissions()` helper added.
- **Deliberately postponed:** no `OrderPaidEvent`/`PaymentFailedEvent`/`ShipmentDeliveredEvent`, no listeners, no `EventServiceProvider` changes, and no calls from Order/Payment/Shipment/Identity. The seven planned events (payment success, payment failed, order cancelled, shipment preparing, shipment sent, shipment delivered, admin paid-order-created) map onto the existing channel list without further schema or contract changes.
- **Tests:** 25 new — `NotificationApiTest` (9: list own, never another user's, resource field allowlist, 401 guest, mark read, idempotent re-read, 403 on another user's, 404 missing), `NotificationDispatchTest` (7: in-app + SMS fan-out, in-app-only, SMS-only with null `notification_id`, failed provider → failed delivery, no-phone recipient, missing payload, API round-trip), `SmsManagerTest` (9: configured-provider resolution, provider switching, unknown-provider exception and degraded result, SMS.ir payload translation, transport failure, application-level rejection, no call without credentials/template, fake provider). All SMS tests run under `Http::preventStrayRequests()`, so no test can reach a real SMS API.

### Change — Payment: gateway callback renders a Blade result page instead of JSON

- `GET /api/v1/payments/zarinpal/callback` **stays on the backend domain** and is still public/unauthenticated (`api` + `throttle:public`, GET). All verification behavior is unchanged: callback data extraction, payment lookup by `Authority`, amount validation, server-side gateway verification, transaction/reference persistence, the already-captured idempotency guard, `OrderManagerInterface::markAsPaid()` on success (which commits inventory and activates the shipment exactly once), failure recording, exception handling, and logging. **Only the final presentation changed** — the endpoint now returns a rendered Blade page instead of a JSON body.
- The displayed success state is derived from the **persisted, verified Payment record** (`status === captured`), never from raw callback parameters such as `Status=OK`. When no Payment can safely be resolved (missing/unknown `Authority`), the page renders a generic failure state with neutral placeholders (`نامشخص` / `—`) and no internal messages, stack traces, gateway credentials, or callback payloads.
- The page's two buttons navigate to the frontend application: "بازگشت به فروشگاه" → `config('frontend.url')`, and "پیگیری سفارش" → `{frontend.url}/{frontend.order_path}/{orderId}` where `orderId` comes from the **stored Payment record**, never from a query parameter. Callback query parameters cannot override either URL; the tracking button renders disabled when no order can be resolved. No payment status, transaction id, or other sensitive value is placed in the frontend query string.
- New `config/frontend.php` (`frontend.url`, `frontend.order_path`) backed by new `.env.example` keys `FRONTEND_URL` (falls back to `APP_URL`) and `FRONTEND_ORDER_PATH` (default `orders`). Trailing slashes are normalized so URLs never become malformed, and `env()` is never called from the controller or the view — config caching is safe. Assumption: the frontend exposes a customer order page at `{FRONTEND_URL}/orders/{id}`; change `FRONTEND_ORDER_PATH` if it differs.
- `PaymentServiceProvider::boot()` registers the module view directory under the `payment` namespace (`loadViewsFrom(__DIR__.'/../Resources/views', 'payment')`), so the page is addressable as `payment::result` and stays inside the module. The view lives at `Modules/Payment/Infrastructure/Resources/views/result.blade.php`, converted from the storefront template pages `successful-payment.html` / `failed-payment.html` (identical except for icon/colour/labels, so they collapse into one view driven by `$success`). It renders the breadcrumb + result card only — the template's header/footer are omitted because they require `scripts/app.js` and `swiper.css`, which do not ship with this backend, and link to storefront pages that do not exist on this domain. It is self-contained (no shared `layouts.app` dependency, which does not exist in this project) and loads `public/modules/payment/app.css` through `asset('modules/payment/app.css')` — no Vite entry, no inline CSS, no module-filesystem reads.
- Known gap: `public/modules/payment/app.css` declares `@font-face src: url("../fonts/Dana/…")`, which resolves to `public/modules/fonts/…`, and no font files ship with the repo — so the Persian Dana/Morabba webfonts fall back to a system font. Drop the `woff2` files into `public/modules/fonts/` to fix. (The shipped build also has a typo around line 709: `Morabba-Bold.woff2f`.)
- Tests: `tests/Feature/Payment/PaymentTest.php` 12 → 15. Existing callback tests now assert the rendered view and its data instead of JSON; new cases cover the missing-`Authority` generic failure page, configured frontend URLs with an attacker-supplied `frontend=` parameter ignored, neutral placeholders with no internal-message leakage on an unresolved payment, and the CSS asset URL. All 15 green.

### Feature — Order: admin order management (view / search / cancel)

- New admin/operator surface under `/api/v1/admin/orders`: `GET /` (paginated, filterable by `status`/`order_id`/`user_id`/`date_from`/`date_to`), `GET /{order}` (full detail), and `POST /{order}/cancel`. Deliberately **view/search/cancel only** — order status transitions remain owned by the Shipment module, so there is no status-change, create, or edit endpoint.
- New `OrderPolicy` (`viewAny`/`view` → `order.view-admin`, `cancel` → `order.cancel-admin`), typehinted against `Authorizable` and registered via `Gate::policy()` in `OrderServiceProvider::boot()`. Adds the `order.cancel-admin` permission (granted to `admin` only); `order.view-admin` was already seeded and is now actually enforced.
- `OrderManagerInterface` gains `getAdminOrders(array $filters, int $perPage)` (returns OrderDTO paginator) and `getAdminOrderDetail(int $orderId): ?AdminOrderDetailDTO`. The detail DTO composes the OrderDTO with the live shipment state resolved **only through `ShipmentManagerInterface::findForOrder`** — no Shipment model access from Order.
- Admin list/detail read customer and product data from the frozen `customer_snapshot` / `product_snapshot` (no per-order Identity/Catalog queries). New `AdminOrderListResource` (lightweight row + `item_count`) and `AdminOrderResource` (full detail + shipment status block); customer `OrderResource`/`OrderItemResource` are untouched.
- `AdminCancelOrderAction` reuses `CancelOrderAction::releaseAndCancel` (no duplicated release/cancel logic) and is restricted to `pending` orders; paid/shipped cancellation (refund + committed-stock return) remains a separate future flow.
- Tests: `AdminOrderTest` (11) covers list + status filter, detail with snapshots and null shipment, 404, the full permission matrix (customer 403 on list/detail/cancel, 401 unauthenticated), admin cancel releasing inventory, non-pending 422, and asserts no status-mutation/create endpoints exist (404/405).

### Feature — Order: immutable customer &amp; product snapshots

- Orders are historical transaction records — a customer editing their profile or an admin renaming/repricing a product must never change what a past order shows. `orders` gains nullable `customer_snapshot` (JSON: `name`, `last_name`, `phone`, `email`) and `order_items` gains nullable `product_snapshot` (JSON: `title`, `sku`, `image_url`, `attributes`), both captured once at checkout and never rewritten.
- Identity's `IdentityManagerInterface` gains `getUserSummary(int $userId): UserSummaryDTO` (new `Domain/DTOs/UserSummaryDTO.php`), implemented in `EloquentIdentityManager`. This is the only way `CreateOrderAction` reads customer identity — the `User` model is never imported into Order.
- The product snapshot is built entirely from the already-enriched `CartItemDTO` (`sku`, `productName`, `imageUrl`, `attributes`) inside `CreateOrderAction` — **no second Catalog call** and no `Product`/`ProductVariant` model access from Order.
- `OrderDTO`/`OrderItemDTO` and `OrderResource`/`OrderItemResource` expose `customer_snapshot` / `product_snapshot` alongside the existing fields (`product_title`, `variant_attributes`, `sku`, `price_per_unit`, `quantity`, etc. — none removed).
- Tests: `OrderTest` gains coverage for snapshot capture on creation and for immutability after a later profile update and a later product title update; existing inventory-reservation, shipment-hold, and pending-status behavior is unchanged and still green.
- `OrderSampleDataSeeder` now populates both snapshots for every demo order (customer fields off the seeded `User`, product fields resolved once via `CatalogManagerInterface::findVariantBySku()`), so sample/demo data matches real checkout output instead of leaving the new columns null.
- `API_DOCUMENTATION.html` — the Orders section (`create`/`list`/`cancel` response examples) now shows `customer_snapshot` and `product_snapshot`, plus a note explaining they're frozen at checkout and never rewritten.

### Feature — Shipment: delivery working-period admin API

- Added permission-protected CRUD endpoints at `/api/v1/admin/shipment/delivery-working-periods` for recurring weekday/time templates used by `shipment:generate-delivery-slots`.
- Reuses `shipment.slot.view-admin` for listing and `shipment.slot.manage` for mutations; validates weekday 0–6, end-after-start, booleans, partial updates, and prevents overlapping periods on the same weekday.
- Existing generated dated slots are not rewritten when templates change. Added feature coverage for CRUD, validation, authorization, overlap handling, and idempotent generation.
### Feature — Catalog/Cart/Order: per-variant order quantity limits

- Catalog variants now store nullable `max_quantity_per_order` (`null` means no special limit; configured values must be whole integers of at least 1). Standalone variant writes, nested product creation, and product-update variant upserts all accept it, including explicit `null` to clear it.
- `ProductVariantDTO` and variant responses expose the limit plus `effective_max_quantity` where stock is available. Catalog publishes batch `getVariantsBySkus()` DTO lookup so Cart and Order do not query Catalog models or perform N+1 SKU lookups.
- Guest and authenticated Cart add/update operations reject quantities above the variant limit with the standard 422 validation shape. Adds validate the resulting quantity; automatic guest-cart merge clamps to the lower of combined quantity, available stock, and the configured limit.
- Cart items expose `max_quantity_per_order`, `effective_max_quantity`, `remaining_addable_quantity`, and `quantity_valid`, allowing clients to detect carts made stale by an administrator lowering a limit.
- Checkout aggregates quantities by SKU and reloads current Catalog rules before creating/cancelling an Order, reserving Inventory, or holding a delivery slot. Rejection leaves every existing Order, reservation, slot hold, and Cart unchanged.
- Order items snapshot the applied rule in nullable `max_quantity_per_order_snapshot`. Historical and previous Orders are never counted; the rule is independent per Order and Inventory may impose a lower maximum.(https://github.com/laravel/laravel/compare/v12.12.1...12.x)

### Feature — Shipment: fulfillment module (postal / local delivery / pickup)

**A complete `Shipment` bounded context handling checkout → payment → delivery, postal handoff, or store pickup.** The four fulfillment methods are fixed and **configuration-backed** (no `shipment_methods` table); the admin cannot create, rename, reprice, or toggle them from the panel.

#### Added
- `config/shipment.php` — the four fixed methods (`post_standard`, `post_express`, `local_delivery`, `in_person_pickup`) keyed by stable **code**, integer-rial prices, plus local-delivery slot-generation and pending-order-TTL tunables (env-backed).
- Tables: `shipments` (operational record, unique `public_code` + unique `order_id`, loose `user_id`/media refs, address/slot/pickup JSON snapshots, per-status timestamps), `shipment_status_histories` (append-only), `delivery_working_periods` (recurring weekly templates), `delivery_slots` (generated dated sessions, unique `[date, starts_at, ends_at]`, `capacity` + `admin_reserved_capacity`), `delivery_slot_reservations` (source of truth for consumed capacity), `delivery_schedule_exceptions` (`closed` / `custom_hours`).
- `ShipmentManagerInterface` (public contract) + `LocalDeliveryEligibilityInterface` (isolated supported-region rule, default permissive). DTOs: `ShipmentMethodDTO`, `ShipmentSelectionDTO`, `DeliverySlotDTO`, `DeliverySlotReservationDTO`, `ShipmentDTO`, `ShipmentStatusHistoryDTO`.
- Method-specific workflows (`Postal`/`LocalDelivery`/`Pickup` + `ShipmentWorkflowResolver`) and `ShipmentTransitionService` (lock → validate transition → mutate + timestamp → history → sync order status → reservation). Focused Actions per business step (start-preparing, mark-ready-for-post, hand-to-post, mark-ready-for-dispatch, out-for-delivery, delivered, delivery-failed, reschedule, ready-for-pickup, confirm-pickup) + slot Actions (generate, close, open, update-capacity). No generic "set status" endpoint.
- `shipment:generate-delivery-slots {--days=}` (idempotent cinema-session generation; scheduled daily at 00:30). Availability service computes remaining = capacity − admin_reserved − active(held+confirmed) and enforces lead-time / booking-horizon / closed-date / capacity rules; `holdForPendingOrder` re-checks under `lockForUpdate()` to prevent overbooking.
- Customer endpoints: `GET /shipment/methods`, `GET /shipment/delivery-slots`, `GET /shipments/{publicCode}`, `GET /orders/{order}/shipment`. Admin/operator: `GET /admin/shipments[/{publicCode}]` + business-action POSTs + slot management (`GET/PATCH /admin/shipment/delivery-slots[/{slot}]`, `.../close`, `.../open`). All `throttle:api`.
- Permissions (permission-based, 403-before-validation): `shipment.view-own`, `shipment.view-admin`, `shipment.start-preparing`, `shipment.post.{mark-ready,hand-over}`, `shipment.delivery.{mark-ready,dispatch,complete,fail,reschedule}`, `shipment.pickup.{mark-ready,complete}`, `shipment.slot.{view-admin,manage,close,reserve-capacity}`. `ShipmentServiceProvider` registered in `bootstrap/providers.php`.

#### Changed
- **Checkout now uses `shipment_method_code`** (+ `address_id?`, `delivery_slot_id?`). `orders` gains `shipment_method_code` and an immutable `shipment_snapshot` JSON; the legacy `shipment_method_id` column is kept nullable for backward compatibility but is no longer written. `OrderManagerInterface::createOrderFromCart` now takes a `ShipmentSelectionDTO`; added `syncStatusFromShipment`; `OrderStatus` gains `completed`.
- **`markAsPaid` is now the shared, idempotent, row-locked paid path:** it commits the inventory reservation exactly once (previously never committed on payment) and activates the operational shipment (idempotent by `order_id`). Pending-order cancel/expiry/replacement release any held local-delivery slot via `CancelOrderAction::releaseAndCancel`.

#### Tests
- `tests/Feature/Shipment/` (ShipmentMethods, ShipmentSlot, ShipmentPaymentIntegration, ShipmentWorkflow, ShipmentAuthorization, AdminDeliverySlot, AdminShipmentIndex) + `tests/Unit/Shipment/ShipmentWorkflowTest` — **55 tests, 225 assertions**. Existing Order/Payment tests updated for the new checkout contract; no regressions.

### Feature — Catalog: Brands

**Products can now belong to a brand.** Brands are a flat catalog lookup with public reads and permission-gated admin writes.

#### Added
- `brands` table (`name`, unique `slug`, loose `media_id`, `is_active`) + nullable `products.brand_id` FK (`nullOnDelete` — deleting a brand unlinks its products rather than deleting them).
- Endpoints: `GET /api/v1/catalog/brands` (paginated, `search` on name), `GET /api/v1/catalog/brands/{id}`, and admin `POST` / `PATCH` / `DELETE /api/v1/catalog/brands/{id}` behind `auth:sanctum` + `catalog.brand.{create,update,delete}`. Logo attaches via inline `image` upload OR pre-uploaded `media_id` (mutually exclusive).
- `Brand` model + `BrandPolicy`, `BrandDTO` / `BrandResource` (exposes resolved `image_url`), `Create`/`Update`/`DeleteBrandAction`, and `CatalogManagerInterface` methods `findBrand` / `getBrands` / `createBrand` / `updateBrand` / `deleteBrand`.
- Product surface: read responses carry `brand_id`; create/update accept `brand_id` (`exists:brands,id`); public + admin product lists accept a `brand_id` filter; free-text product `search` also matches brand name.

#### Fixed
- Seeded the `catalog.brand.{create,update,delete}` permissions in `CatalogPermissionsSeeder` (granted to `admin`). Without this the brand write endpoints would have returned 403 for everyone, since the policy/form-requests reference permissions that were never created.

#### Tests
- `BrandTest` (14): public list/search/show + 404, admin create (slug auto-gen) / update / delete, delete unlinks referring products, validation (missing name, duplicate slug), full auth matrix (401/403), and product `brand_id` filtering.

#### Docs
- `API_DOCUMENTATION.html` gains a **Catalog — Brands** section and `brand_id` on the product filter/body/response docs.

### Fix — Order & Payment: enforce ownership and release stock on cancel

**A customer could pay for (and, via `in_person`, mark paid) another user's order, and there was no user-facing way to cancel an order — so reserved stock was only ever freed by the 15-min expiry sweep.**

#### Added
- `POST /api/v1/orders/{order}/cancel` — a user cancels **their own** pending order; releases every item's reserved stock and returns the cancelled OrderResource. `auth:sanctum`; 403 for another user's order, 404 if missing, 422 unless the order is `pending`.
- `CancelOrderAction` — owns the single `releaseAndCancel(Order)` primitive (release reservations + mark cancelled) now reused by `CreateOrderAction` (pending replacement) and `CancelExpiredOrdersAction`, so every cancellation path frees stock through one code path.

#### Changed
- `InitializePaymentAction` / `PaymentManagerInterface::initializePayment` now take the caller's `userId` and **abort 403 unless the target order belongs to that user** (checked right after the 404 guard) — closes the cross-user payment hole.
- `CancelExpiredOrdersAction` now delegates to `CancelOrderAction::releaseAndCancel` (each order in its own transaction) instead of inlining the release loop.

#### Tests
- `OrderTest` (+5): user cancels own order releases stock, cancel ownership 403, cancel 404, cancel non-pending 422, unauthenticated cancel 401.
- `PaymentTest` (+1): initializing payment for another user's order is 403 and writes no payment row.

### Feature — Catalog: per-variant available stock on the product resource

**Product read responses now report how many units of each variant are available.**

#### Added
- `ProductVariantResource` emits `stock` — available units (`physical − reserved`) for the variant's SKU. Present on every product read (`GET /products`, `/products/{uuid}`, `/products/{uuid}/admin`, `/categories/{id}/products`, `/products/admin`) and the standalone `/variants/*` endpoints.
- `ProductVariantDTO::$availableStock` (nullable int) carries the figure.

#### Changed
- `EloquentCatalogManager` now depends on `InventoryManagerInterface` and enriches variant DTOs via `getBatchStockBySkus()` — a single page-wide batch call for lists (no per-product fan-out), a single lookup for standalone variant reads. Respects module isolation (contract + DTO, no cross-module join). SKUs with no inventory record report `0`.

#### Tests
- `ProductsTest` (+2): available stock exposed per variant; a variant with no inventory record reports `0`.

### Removed — Catalog: caching layer (deferred)

**The Catalog read-cache decorator is removed for now; caching will be reintroduced later as a deliberate, standalone piece of work.**

#### Removed
- `CachedCatalogManager` (read-through cache + version-bump invalidation decorator), `config/catalog.php` (the `cache.enabled` / `cache.ttl` block and its `CATALOG_CACHE_*` env keys), and `CatalogCacheTest`.
- `CatalogServiceProvider` now binds `CatalogManagerInterface` straight to `EloquentCatalogManager` unconditionally — no cache branch, no config merge.

> Earlier Unreleased notes that mentioned the cache decorator have been reconciled to match; it was never part of a tagged release.

### Change — Catalog: shorten the product public code to 7 chars

**The product public identifier is now a short 7-char hex code (with a real DB uniqueness check), not a v4 UUID — shorter, friendlier URLs while staying opaque and non-enumerable.**

#### Changed
- `Product::generateUniqueUuid()` generates the code as `substr(bin2hex(random_bytes(4)), 0, 7)` and **loops against a DB uniqueness check** (`where('uuid', $code)->exists()`) until unique. Used by the model hook on `POST /products` create.
- `products.uuid` column retyped from the native `uuid`/`char(36)` to `string(16)` (migration `2026_07_08_000000_add_uuid_to_products_table`), so short codes are valid on Postgres. The backfill loop generates the same 7-char codes with a uniqueness check.
- Product-level route constraints changed from `whereUuid` to `->where('uuid', '[0-9a-fA-F\-]+')` — 7-char hex still matches, reserved segments (`admin`, `slug`) are still excluded, so no route shadows.
- Seeders: `CatalogSampleDataSeeder` calls `Product::generateUniqueUuid()` explicitly (seeders run under `WithoutModelEvents`, which mutes the model hook), so seeded products get codes the same way a real create does.

#### Tests
- `ProductsTest` UUID assertion tightened to match `^[0-9a-f]{7}$`.

### Chore — demo data seeders across Catalog, Inventory, Orders & Payments

**`php artisan db:seed` now populates realistic sample data end-to-end so the API has something to show.**

#### Added
- `CatalogSampleDataSeeder` — 5 products (Galaxy S25, iPhone 16, MacBook Pro 14, AirPods Pro 2, USB-C Hub) with variants across 3 categories, each with a generated 7-char public code.
- `InventorySampleDataSeeder` — seeds an opening `restock` ledger entry (50 units) per SKU via `adjustStock`, so ledger history is accurate from the start.
- `OrderSampleDataSeeder` — 3 demo customers + addresses, an active user cart and a guest cart, and 9 orders spanning every status. Drives the **real** Inventory reservation lifecycle (reserve on create; commit for paid/processing/shipped; release for cancelled/failed; pending keeps its hold), then runs `SyncSalesCountsAction` so `sales_count` / `?sort=most_sold` has data. Idempotent (`[demo]` note marker), backdated `created_at`.
- `PaymentSampleDataSeeder` — a `Payment` per realized order matching its status: in-person (`CASH-` ref) → `pending_cash`; online (`REF-` ref) → `captured`; failed → `failed`; pending → `initiated`.
- All four registered in `database/seeders/DatabaseSeeder.php` after the permission seeders.

### Feature — Catalog: product sort (`cheapest` / `most_expensive` / `most_sold`)

**Product listing endpoints accept a `?sort=` param; best-seller ordering is powered by a denormalized counter kept in sync from the Order module.**

#### Added
- `products.sales_count` — indexed, denormalized best-seller counter (migration `2026_07_09_000000_add_sales_count_to_products_table`, default 0). Never client-accepted; exposed as `sales_count` on `ProductResource`.
- `CatalogManagerInterface::syncSalesCounts(array $skuTotals)` — absolute per-SKU tally (`sku => units`) pushed across the module boundary. Catalog resolves SKU → variant → product, sums per product, and resets products with no sales to 0. Unknown SKUs are ignored.
- `OrderStatus::soldStatuses()` — `[paid, processing, shipped]`, the statuses that count as a realized sale.
- Order module: `SyncSalesCountsAction` (aggregates `order_items.quantity` by SKU for realized orders, entirely within Order's own tables) + `orders:sync-sales-counts` console command, scheduled **hourly** in `routes/console.php`.

#### Changed
- `GET /catalog/products`, `GET /catalog/categories/{id}/products`, and `GET /catalog/products/admin` accept `sort` ∈ {`cheapest`, `most_expensive`, `most_sold`}. Price sorts order by the **default variant's** `base_price` (correlated subquery); `most_sold` orders by `sales_count`. Absent/invalid `sort` → newest-first default (invalid value → 422). Admin index default ordering unchanged (newest-first).

#### Tests
- `ProductSortTest` (10) covers the three sorts, admin sort, `sales_count` exposure, invalid-value 422, and `syncSalesCounts` semantics; `SalesCountSyncTest` (2) covers the cross-module artisan recompute (realized-only, zeroing). Suite: 288 green.

### Change — Catalog: products addressed by public UUID

**Products now expose an opaque UUID as their public identifier; the API routes and response `id` use the UUID instead of the auto-increment integer.**

#### Added
- `products.uuid` — unique, indexed, server-generated column (migration `2026_07_08_000000_add_uuid_to_products_table`, backfills existing rows). Auto-assigned on create by the `Product` model hook; **never accepted from client input** (mirrors the SKU rule).
- `ProductDTO` gains a `uuid` field; `ProductResource` now emits the UUID as `id`.

> **Superseded within Unreleased** by "shorten the product public code to 7 chars" below: the code is now a 7-char hex string (not a v4 UUID), the column is `string(16)`, and the route constraint is `->where('uuid', '[0-9a-fA-F\-]+')` (not `whereUuid`). The route set and "never client-accepted" contract are unchanged.

#### Changed
- Product-level routes are now `{uuid}`: `GET /products/{uuid}`, `GET /products/{uuid}/admin`, `PATCH /products/{uuid}`, `DELETE /products/{uuid}`, `POST /products/{uuid}/gallery`, `DELETE /products/{uuid}/gallery/{imageId}`, `POST /products/{uuid}/variants`. Numeric ids now `404` on these routes; the constraint also prevents `/products/{uuid}` from shadowing `/products/admin` (replaces the old `whereNumber` guard).
- `CatalogManagerInterface` product-aggregate methods (`findProduct`, `findProductAdmin`, `updateProduct`, `deleteProduct`) take `string $uuid`. The FK-insert helpers (`addProductImage`, `createProductVariant`) keep the internal integer product id; controllers resolve `uuid → product` and pass the integer inward. `UpdateProductRequest` slug-uniqueness now ignores the current product by `uuid`.

#### Unchanged
- The integer primary key remains the internal key and the FK target for `product_variants` / `product_images` (no FK migration). SKUs still embed the internal integer id (`bdp{id}-v{n}`). Cart / Inventory / Order are unaffected — they link to Catalog by SKU.

#### Tests
- Catalog feature tests route by `$product->uuid` and assert the response `id` is the UUID; added coverage that the UUID is generated and exposed (not the integer) and that a numeric id `404`s. Full suite green.

### Change — Identity: move password-setting off OTP verify + add `last_name`

**OTP verify now only proves phone ownership; passwords are set by an authenticated endpoint, and the profile gains a family name.**

#### Changed
- `VerifyOtp` action + `VerifyOtpRequest` — no longer accept `name` or `password`. Verify validates and consumes the code and mints a token; that's it. A display name is captured at `POST /api/v1/otp/request`; a password is set via the new set-password endpoint.

#### Added
- `Modules/Identity/Application/Actions/SetPassword.php` + `SetPasswordRequest.php` — an authenticated user sets or replaces their own password (`required`, 8–255, `confirmed` — needs a matching `password_confirmation`, hashed via `Hash::make`). The Sanctum token proves ownership, so no current password is required.
- `AuthController::setPassword()` and route `POST /api/v1/auth/set-password` (`auth:sanctum`, `throttle:api`). Guests → `401`.
- `users.last_name` — nullable family name (migration `2026_07_07_000001_add_last_name_to_users_table`). Mass-assignable on `User`, seeded by `UserFactory`, settable at OTP registration (`POST /api/v1/otp/request`) and via profile update (`PATCH /api/v1/profile`, admin `PATCH /api/v1/admin/users/{user}`), and exposed on `UserResource` + `AuthUserResource`.

#### Tests
- `PasswordAuthTest` reworked (15 tests): OTP registration leaves the account password-less, verify silently ignores a stray `password`, and the authenticated set-password flow is covered (hash storage, subsequent login, short/missing password → 422, guest → 401).
- `ProfileTest` (+1) and `AuthControllerTest` extended for `last_name` view/update/registration.

### Feat — Identity Module: password authentication alongside OTP (split-auth onboarding)

**Returning users can now log in with a password; new users still verify phone ownership via OTP first.**

#### Added
- `Modules/Identity/Application/Actions/CheckUserStatus.php` — resolves which auth methods a phone may use. Unknown phone → `{is_new_user: true, allowed_methods: ["otp"]}`; known phone → `{is_new_user: false, allowed_methods: ["password", "otp"]}`.
- `Modules/Identity/Application/Actions/LoginWithPassword.php` — finds the user by phone and verifies via `Hash::check()`. Unknown phone, password-less account, and wrong password all return the same generic **401 `Invalid credentials.`** (no account-existence leak). On success mints a Sanctum token.
- `Modules/Identity/Infrastructure/Http/Requests/CheckUserRequest.php` + `LoginPasswordRequest.php` — validate `phone_number` (`09XXXXXXXXX`); login also requires `password` and accepts optional `device_name`.
- `AuthController::checkUser()` and `AuthController::loginPassword()`.
- Routes: `POST /api/v1/auth/check-user` (`throttle:public`) and `POST /api/v1/auth/login-password` (`throttle:otp`, strict per-IP brute-force limiter).
- Migration `2026_06_27_120000_ensure_users_password_nullable.php` — defensive, idempotent guarantee that `users.password` exists and is nullable (no-op on current schema; the column was already made nullable in the OTP refactor).

#### Changed
- `VerifyOtp` action + `VerifyOtpRequest` — registration completion (OTP verify) now accepts optional `name` and `password` (8–255 chars). The password is hashed with `Hash::make()` before persistence. Both fields are optional, so the existing OTP-only flow is unchanged.

#### Tests — `tests/Feature/Identity/PasswordAuthTest.php` (11 tests, 42 assertions)
- `/check-user` distinguishes new vs. existing accounts and rejects invalid phones.
- New users register with a password that is stored as a verifiable hash (never cleartext); short passwords rejected; omitting password leaves it `null`.
- Existing users log in with a password and receive a token; wrong password / unknown phone / password-less account / missing password all handled (401 / 422).

**Result: test suite is 245/245 green (692 assertions).**

### Feat — Identity: capture map pin on addresses

**Addresses previously stored `province_id`/`city_id`/`postal_code`/`address` but no geolocation — the exact point a user drops on a map was lost.**

#### Added
- Migration `add_map_location_to_addresses_table`: adds nullable `latitude`/`longitude` (`decimal(10,7)`) and `map_address` (nullable text) columns to `addresses`.
- `StoreAddressRequest` requires `latitude`/`longitude` (`numeric`, `between:-90,90` / `between:-180,180`) on create; `map_address` stays optional. `UpdateAddressRequest` treats all three as `sometimes`.
- `AddressResource` exposes `latitude`, `longitude`, `map_address`.

#### Tests
- `AddressTest`: +4 tests (coordinates required on create, out-of-range rejection, nullable `map_address`, coordinate update); existing create test updated to assert the new fields.

**Result: test suite is 257/257 green (721 assertions).**

### Feat — Catalog: admin all-status product index

**Admins can now list products in every status (draft + published) with pagination and filters — previously the only product listings were the public storefront endpoints, which are limited to published products.**

#### Added
- `GET /api/v1/catalog/products/admin` — paginated products in **any** status, newest first. Gated behind `auth:sanctum` + `catalog.product.view-admin` (401 unauthenticated / 403 unauthorized). Filters: `status` (`draft`/`published`), `category_id`, `min_price`/`max_price` (default variant `base_price`), and `search` (LIKE on title, description, slug, or variant SKU). `per_page` clamped 1–100 (default 15), `page` for the page number.
- `CatalogManagerInterface::getProductsAdmin(array $filters = [], int $perPage = 15)` implemented in `EloquentCatalogManager` (shared filter/pagination helpers `applyProductFilters` + `paginateProducts`).
- `IndexAdminProductsRequest` — validates the filter set and enforces `catalog.product.view-admin` in `authorize()` (403 before validation).
- `ProductsController::indexAdmin()`.

#### Changed
- Public `GET /api/v1/catalog/products/{id}` route now carries a `whereNumber('id')` constraint so it no longer shadows the new `/products/admin` path.

#### Tests
- `ProductsTest`: +9 tests (all-status listing, status/category/price/title/SKU search filters, pagination, combined filters, invalid-status rejection).
- `CatalogAuthorizationTest`: +2 tests (401 unauthenticated, 403 customer).

**Result: test suite is 245/245 green (688 assertions).**

---

### Feat — Order Module: foundational infrastructure

**A complete, production-ready Order module serving as an immutable financial contract anchor.**

#### Added
- `Modules/Order/Domain/Enums/OrderStatus.php` — PHP 8.1 backed enum: `PENDING`, `PAID`, `PROCESSING`, `SHIPPED`, `CANCELLED`, `FAILED`.
- `Modules/Order/Domain/DTOs/OrderDTO.php` + `OrderItemDTO.php` — immutable DTOs with `fromModel()` factory; all monetary fields are integers (Cents Rule).
- `Modules/Order/Domain/Contracts/OrderManagerInterface.php` — public cross-module contract: `createOrderFromCart`, `markAsPaid`, `markAsComplete`, `getUserOrders`, `findOrder`.
- `Modules/Order/Domain/Models/Order.php` + `OrderItem.php` — private Eloquent models; `shipping_address` cast to `array`, all money columns cast to `integer`.
- `Modules/Order/Domain/Exceptions/EmptyCartException.php` + `InvalidAddressException.php`.
- `CreateOrderAction` — full checkout orchestration in `DB::transaction()`: cancels any existing pending order (releases reservations), snapshots cart prices and shipping address, creates order + items, reserves stock per SKU, clears the cart.
- `CancelExpiredOrdersAction` — finds pending orders older than 15 minutes, releases inventory reservations, marks them `cancelled`. Returns count.
- Two migrations: `orders` and `order_items` tables (all monetary columns are integers).
- `EloquentOrderManager` — `getUserOrders` maps paginator items to DTOs via `->through()`.
- `OrderPermissionsSeeder` — `order.create`, `order.view-own`, `order.view-admin`; granted to `admin` + `customer` (create/view-own only).
- `OrderController` — `POST /api/v1/orders` (201) and `GET /api/v1/orders` (paginated).
- `StoreOrderRequest` — `authorize()` checks `order.create` → 403 before validation.
- `OrdersCancelExpiredCommand` (`orders:cancel-expired`) — scheduled every minute in `routes/console.php`.
- `OrderServiceProvider` registered in `bootstrap/providers.php`.
- `seedOrderPermissions()` helper in `tests/TestCase.php`.

#### Tests — `tests/Feature/Order/OrderTest.php` (6 tests, 20 assertions)
- Happy path: price snapshot, stock reserved, cart cleared after order creation.
- Auto-cancel: existing pending order cancelled + reservations released when new order is placed.
- TTL expiry: `orders:cancel-expired` cancels orders older than 15 minutes and releases inventory.
- Auth matrix: 401 unauthenticated, 422 validation, 422 empty cart.

**Result: test suite is 223/223 green (614 assertions).**

---

### Feat — Category `parent` chain and `children` tree on category responses

**Every category response now includes the full ancestor chain upward and the full descendant tree downward.**

#### Added
- `CategoryDTO` — `?self $parent` and `array $children` fields (both optional, backward-compatible defaults).
- `CategoryResource` — `parent` (recursive `CategoryResource` or `null`) and `children` (`CategoryResource::collection`) keys.
- `EloquentCatalogManager::buildCategoryDto()` — unified recursive DTO builder. Builds the parent chain with `$withChildren = false` (no sibling bloat) and the children tree with `$withChildren = true`.
- `EloquentCatalogManager::collectCategoryMediaIds()` — walks both loaded relations to collect media IDs for a single batch `getMediaCollection` call (no N+1).
- `CategoryTreeSeeder` — standalone dev seeder: Electronics root → 4 children (Phones + Laptops each with 2 grandchildren, Tablets, Accessories). Run with `php artisan db:seed --class="Modules\Catalog\Infrastructure\Persistence\Seeders\CategoryTreeSeeder"`.

#### Changed
- `findCategory` — eager-loads `parent.parent.parent.parent.parent` and `children.children.children.children.children` (5-level cap each direction).
- `getActiveRootCategories` — eager-loads `children.children.children.children.children`; roots listing now returns the full subtree nested, making it suitable as a landing-page category menu endpoint.
- `CategoriesTest` — 22 tests (7 new assertions, 2 new parent-chain tests, 2 new children tests).

**Result: test suite is 217/217 green (594 assertions).**

---

### Feat — SMS.ir template-based OTP delivery

**OTP codes are now delivered via SMS.ir's verify/template API in production.**

#### Added
- `Modules/Identity/Infrastructure/Services/SmsIrOtpSender.php` — new `OtpSenderInterface` implementation. Calls `POST https://api.sms.ir/v1/send/verify` with `X-API-KEY` header, converts `09XXXXXXXXX` → `98XXXXXXXXX`, and passes the OTP as a named template parameter.
- `config/identity.php` — new `sms` section: `api_key`, `template_id`, `code_param` (defaults to `"Code"`).
- `.env.example` — `SMSIR_API_KEY=` and `SMSIR_TEMPLATE_ID=` documented with fallback note.

#### Changed
- `IdentityServiceProvider` — `OtpSenderInterface` binding is now conditional: uses `SmsIrOtpSender` when `SMSIR_API_KEY` is non-empty; falls back to `LogOtpSender` automatically in dev/test (no code changes needed to switch environments).

**Result: test suite remains 215/215 green (all OTP tests use the injected mock sender).**

---

### Feat — Auto-generate SKU as `bdp{productId}-v{n}`; variant upsert by ID on PATCH

**SKUs are now generated entirely in the backend. The `sku` field is removed from all request inputs.**

#### Changed
- `CreateProductVariantAction` — generates `bdp{$productId}-v{$n}` inside `DB::transaction()` using `lockForUpdate()` to prevent race conditions. `n` = existing variant count + 1.
- `CreateProductAction` — generates `bdp{$productId}-v{$i+1}` for each variant in the nested-create loop (index-based since the product is always fresh).
- `UpdateProductAction` — variant upsert key changed from SKU to `id`. Submitted variant with a known `id` → `updateProductVariant`; no `id` or unknown `id` → new variant with auto-generated SKU. New variant count tracked via `$newOffset` to avoid collisions within the same request.
- `StoreProductVariantRequest` — removed `sku` required rule.
- `StoreProductRequest` — removed `variants.*.sku` required rule.
- `UpdateProductVariantRequest` — removed `sku` optional rule and unused `Rule` import.
- `UpdateProductRequest` — replaced `variants.*.sku` (required, distinct) with `variants.*.id` (nullable, integer, distinct).
- `ProductVariantsTest` — removed `sku` from all payloads; renamed SKU-duplicate test to `it_generates_unique_skus_for_consecutive_variants`; replaced SKU-update test with `it_does_not_update_sku_even_if_ignored`.
- `ProductsTest` — removed `sku` from nested variant payloads; `test_can_update_product_variants_with_upsert_by_sku` renamed to `test_can_update_product_variants_with_upsert_by_id` and updated to use `id` field.

**Result: test suite is 215/215 green (581 assertions).**

---

### Feat — `type` field on product variants (required, `image` | `color`)

**`ProductVariant` now requires a `type` field (string, `image` or `color`).**

#### Changed
- Migration `2026_06_21_000000_add_type_to_product_variants_table.php` — adds `string('type')` after `sku`.
- `ProductVariant` — added `type` to `$fillable`.
- `ProductVariantDTO` — added `public readonly string $type`; `fromModel()` maps it.
- `ProductVariantResource` — exposes `type` in the JSON response.
- `StoreProductVariantRequest` — `'type' => ['required', 'in:image,color']`.
- `UpdateProductVariantRequest` — `'type' => ['sometimes', 'in:image,color']`.
- `StoreProductRequest` — `'variants.*.type' => ['required', 'in:image,color']`.
- `UpdateProductRequest` — `'variants.*.type' => ['required', 'in:image,color']`.
- `CreateProductVariantAction` — passes `type` through to `createProductVariant`.
- Tests — all `ProductVariant::create()` calls and variant POST/PATCH payloads updated with `type`; 4 new type-specific tests added to `ProductVariantsTest`.

**Result: test suite is 215/215 green (581 assertions).**

---

### Feat — Variant upsert on PATCH /products/{id} + remove inline file uploads

**`PATCH /api/v1/catalog/products/{id}` now accepts an optional `variants` array with upsert-by-SKU semantics.**

#### Changed
- `UpdateProductRequest` — added `variants.*` rules (same fields as `StoreProductRequest`). `withValidator` enforces **at-most-one** `is_default: true` (zero is valid when not changing the default). `prepareForValidation` JSON-decodes a string `variants` field so Scramble's multipart Try-it panel works. Removed `primary_image` file field and `#[BodyParameter]` annotation.
- `UpdateProductAction` — rewritten: wraps the whole operation in `DB::transaction`; strips `variants` before calling `updateProduct`; then iterates submitted variants — SKU already on this product → `updateProductVariant`, new SKU → `createProductVariant`. Dropped `MediaManagerInterface` dependency.
- `StoreProductRequest` — removed `primary_image` / `gallery` file rules and `#[BodyParameter]` annotations. `prohibits:` cross-field constraints removed. File upload must now go through `POST /api/v1/media` first.
- `CreateProductAction` — dropped `MediaManagerInterface` + `UploadedFile` params; media-ID path only.
- `ProductsController` — `store()` and `update()` now pass `$request->validated()` directly; no file extraction.
- `ProductsTest` — removed 2 inline-file tests (feature removed); added 3 update-variant tests (upsert happy path, untouched-variants-when-key-omitted, multiple-defaults 422).

**Result: test suite is 210/210 green (567 assertions).**

---

### Feat — Atomic nested product + variant creation

**`POST /api/v1/catalog/products` now accepts an optional `variants` array to create the product shell and all its variants in a single atomic database transaction.**

#### Changed
- `StoreProductRequest` — added 7 new nested validation rules (`variants.*.sku`, `variants.*.base_price`, `variants.*.compare_at_price`, `variants.*.is_default`, `variants.*.media_id`, `variants.*.attributes`). A `withValidator` hook enforces the single-default invariant: exactly one entry in `variants` must have `is_default: true`. The `variants` field is `nullable` — all existing product creation calls without variants remain valid and unchanged.
- `CreateProductAction` — after images are processed, iterates `$data['variants'] ?? []` inside the existing `DB::transaction`, calling `CatalogManagerInterface::createProductVariant()` for each item. If any variant creation fails the entire operation rolls back, including the product record.
- `ProductsTest` — 3 new feature tests: happy path (2 variants created atomically, both tables asserted), zero-default rejection (422 + transaction rollback verified), multiple-default rejection (422 + rollback verified).

**Result: test suite is 209/209 green (560 assertions).**

---

### Fix — Cart item product_name enrichment

**`product_name` was always `null` in cart item responses even when a Catalog variant existed.**

#### Root cause
`ProductVariantDTO` had no `productName` field. `EloquentCartManager::buildDTO()` had it
hardcoded to `null` because the DTO it received from `CatalogManagerInterface::findVariantBySku()`
never carried the parent product's title.

#### Fixed
- Added nullable `productName` field to `ProductVariantDTO` (backwards-compatible default `null`).
- Updated all 5 `EloquentCatalogManager::fromModel()` call sites to pass the product title:
  `findVariant` and `findVariantBySku` eager-load the `product` relation with `->with('product')`;
  `createProductVariant` and `updateProductVariant` call `$variant->load('product')` after persistence;
  `hydrateProduct` reads `$product->title` from the already-loaded model (zero extra queries).
- `EloquentCartManager::buildDTO()` now passes `$variant?->productName` instead of `null`.
- Added `CartTest::cart_item_includes_product_name_when_catalog_variant_exists()`.

**Result: test suite is 206/206 green (542 assertions).**

---

### Feat — Cart merge endpoint

**`POST /api/v1/cart/merge` — merges a guest session cart into an authenticated user's cart post-login.**

#### Added
- `CartManagerInterface::mergeGuestCart(int $userId, string $sessionId): CartDTO`.
- `EloquentCartManager::mergeGuestCart()` — wrapped in `DB::transaction()`; quantities summed and
  clamped to `availableQuantity`; SKUs with no inventory record skipped silently; guest cart deleted.
- `MergeCartAction`, `MergeCartRequest` (`session_id` required string).
- `CartController::merge()` + route `POST /api/v1/cart/merge` under `auth:sanctum` middleware.
- 6 feature tests: happy path, overlapping SKUs, stock cap, unknown session, 401 unauthenticated,
  missing inventory skip.
- Full frontend implementation guide added to `API_DOCUMENTATION.html`.

---

### Cart Module (v1.0.0)

**Complete implementation of guest + authenticated cart with real-time stock validation and Catalog price enrichment.**

#### Added
- **Migrations:** `carts` (user_id nullable loose bigint, session_id nullable indexed) and `cart_items` (cart_id FK→carts cascade, sku indexed, quantity uint, unique constraint on cart_id+sku).
- **Domain Models:** `Cart`, `CartItem` with `items()` / `cart()` relations.
- **DTOs:** `CartItemDTO` (id, cartId, sku, quantity, productName, basePrice, compareAtPrice, imageUrl, lineTotal — all prices as integers) and `CartDTO` (id, userId, sessionId, items array, itemCount, totalQuantity, totalPrice).
- **Custom exceptions:** `CartItemNotFoundException`, `InsufficientStockException`, `ProductSkuNotFoundException` (`Domain/Exceptions/`).
- **`CartManagerInterface`** (public contract): `findOrCreateCart`, `getCart`, `addItem`, `removeItem`, `updateQuantity`, `clearCart`.
- **`EloquentCartManager`**: injects `CatalogManagerInterface` (price enrichment) and `InventoryManagerInterface` (stock queries); unique-SKU addItem increments existing quantity.
- **Five application actions:** `AddToCartAction` (stock-validates via Inventory, re-throws as Cart-domain exception), `GetCartAction`, `UpdateCartItemAction`, `RemoveFromCartAction`, `ClearCartAction`.
- **`CartIdentificationMiddleware`** (`cart.identify`): tries `auth('sanctum')` without requiring it; falls back to `X-Session-Id` header; auto-generates UUID session if neither present; echoes session back as `X-Cart-Session-Id` response header.
- **`CartController`** with structured exception→HTTP mapping (ProductSkuNotFoundException/InsufficientStockException → 422, CartItemNotFoundException → 404).
- **`AddCartItemRequest`** / **`UpdateCartItemRequest`** — `authorize()` returns `true` (self-service), Cents-Rule `prepareForValidation()` casts quantity.
- **`CartResource`** / **`CartItemResource`** (accept DTOs, never Eloquent models).
- **Routes** (`api/v1/cart`) under `cart.identify` middleware: `GET /` show, `POST /items` add, `PATCH /items/{itemId}` update, `DELETE /items/{itemId}` remove, `DELETE /` clear.
- **`CartServiceProvider`** registered in `bootstrap/providers.php`; registers `cart.identify` middleware alias.
- **15 feature tests** in `CartTest`: guest empty cart, add item, same-SKU increment, zero-stock 422, missing-SKU 422, validation errors, auth user isolation, guest/auth cart isolation, update quantity, update-exceeds-stock 422, remove item, remove-nonexistent 404, clear cart 204.

**Result: test suite is 199/199 green (522 assertions).**

---

### Inventory Module (v1.0.0)

**Complete implementation of the stock tracking and reservation module.**

#### Added
- **Migrations:** `inventory_stocks` (sku unique+indexed, quantity, reserved_quantity) and `inventory_ledger_entries` (append-only: sku, type enum, quantity_change signed int, reference_type, reference_id, notes, created_at only).
- **Domain Models:** `InventoryStock`, `InventoryLedgerEntry` (`UPDATED_AT = null` — immutable audit rows).
- **DTO:** `InventoryStockDTO` with `sku`, `availableQuantity` (quantity − reserved), `physicalQuantity`, `reservedQuantity`.
- **Custom exceptions:** `StockNotFoundException`, `InsufficientStockException` (`Domain/Exceptions/`).
- **`InventoryManagerInterface`** (public contract): `getStockBySku`, `getBatchStockBySkus`, `adjustStock`, `reserveStock`, `commitReservation`, `releaseReservation`.
- **`EloquentInventoryManager`**: all mutations wrapped in `DB::transaction()` + `lockForUpdate()` to eliminate concurrent-checkout oversell races.
- **Four application actions:** `UpdateStockAction`, `ReserveStockAction`, `CommitReservationAction`, `ReleaseReservationAction`.
- **`InventoryPolicy`** — `manage` and `viewLedger` methods, typehinted against `Authorizable` (no Identity model import). Registered by `InventoryAuthServiceProvider`.
- **`InventoryController`** with `AdjustStockRequest` (permission-based `authorize()` — 403 before validation) and `BatchStockRequest`.
- **`InventoryStockResource`** (wraps DTO) and **`InventoryLedgerEntryResource`** (wraps Eloquent model, within same module).
- **Routes** (`api/v1/inventory`): public `GET /sku/{sku}` and `POST /batch`; admin `POST /adjust` and `GET /sku/{sku}/ledger`.
- **`InventoryPermissionsSeeder`**: seeds `inventory.stock.manage` and `inventory.ledger.view`, both granted to `admin`.
- **`InventoryServiceProvider`** registered in `bootstrap/providers.php`.
- **`TestCase::seedInventoryPermissions()`** helper added.
- **24 feature tests** across `InventoryTest` and `InventoryAuthorizationTest`: public 200/404, batch omit-unknowns, admin restock + ledger, reserve/commit/release lifecycle, oversell prevention, full 401/403/public auth matrix, permission-not-role proofs.

**Result: test suite is 172/172 green (433 assertions).**

---

### Identity Module — Passwordless OTP Authentication

**Replaced password-based register/login with a unified phone OTP flow (sign-up == login).**

#### Added
- Migration `2026_06_15_120000_refactor_users_table_for_otp_auth`: `password` and `name` made nullable; added `otp_code` (stored hashed, hidden), `otp_expires_at` (datetime), and a loose FK-free `media_id` profile-image column.
- `POST /api/v1/otp/request` — body `phone` (`09xxxxxxxxx`) + optional `name`. Finds-or-creates the user (assigns `customer` role on first contact), generates a numeric code, stores its hash with a TTL, and dispatches it. Returns `200 {message, expires_in}`.
- `POST /api/v1/otp/verify` — body `phone`, `code`, `device_name`. Validates presence/expiry/hash, consumes the single-use code, and mints a Sanctum token. Returns `200 {message, user, token}`; `422` on `code` otherwise.
- `OtpSenderInterface` delivery boundary, bound to a log-only `LogOtpSender` placeholder until the SMS web service is connected.
- `RequestOtp` / `VerifyOtp` application actions, `RequestOtpRequest` / `VerifyOtpRequest`.
- `config/identity.php` → `otp.length` (default 5), `otp.ttl_minutes` (default 2); `.env.example` keys `OTP_LENGTH`, `OTP_TTL_MINUTES`.
- `AuthControllerTest` rewritten — 10 feature tests covering create-on-request, no-duplicate, invalid phone, verify+token, wrong/expired/unknown code, single-use replay protection, me, and logout.

#### Removed
- Password endpoints and supporting classes: `RegisterUser`, `LoginUserWithPassword` actions and `RegisterRequest`, `LoginRequest` form requests.

**Result: test suite is 148/148 green.**

---

### Identity Module — Bug fixes & test alignment

#### Fixed
- `UpdateAddressRequest`: `city_id` was `required` instead of `sometimes`, causing all partial PATCH requests to the address endpoint to fail with 422. Changed to `sometimes` so callers can update individual fields without resending city.
- Address routes registered under `prefix('address')` (singular) but tests and intended API contract used `prefix('addresses')` (plural). Renamed to `addresses` — the plural RESTful convention.
- `ProfileTest`: 5 tests were calling non-existent URLs (`/api/v1/profile/show/{id}`, `/api/v1/profile/{id}` PATCH/DELETE). Updated to the real admin user management URLs (`/api/v1/admin/users/show/{id}`, `/api/v1/admin/users/{id}`).
- `MediaUploadTest`: removed the oversize-image test case after the `max:4096` rule was intentionally dropped from `StoreMediaRequest` (file size is now enforced by server config, not the application layer).

**Result: test suite is now 146/146 green (was 128/146).**

---

### Media Module — Standalone Upload/Delete Endpoints

**Added HTTP endpoints to the Media module so clients can upload files independently of any Catalog write operation.**

#### Added
- `POST /api/v1/media` — accepts `file` (image, max 4 MB) + optional `folder` string; returns `201 {id, url, mime_type, file_size, original_name}`. Gates on `media.upload` permission via `StoreMediaRequest::authorize()`.
- `DELETE /api/v1/media/{id}` — removes physical file and ledger row; returns `204` or `404`. Gates on `media.delete` permission via `MediaPolicy`.
- `MediaPolicy` (`Domain\Policies\`) with `upload()` and `delete()` methods, typehinted against `Authorizable`.
- `MediaAuthServiceProvider` — registers the policy; booted from `MediaServiceProvider::register()`.
- `MediaPermissionsSeeder` — seeds `media.upload` and `media.delete` permissions; both granted to the `admin` role.
- `MediaResource` — thin JSON resource wrapping `MediaDTO` (id, url, mime_type, file_size, original_name).
- `seedMediaPermissions()` test helper added to `tests/TestCase.php`.
- `MediaUploadTest` — 13 feature tests covering auth boundaries, happy paths, validation, delete, and permission-not-role proof.

#### Context
The existing `media_id` / `primary_media_id` link inputs on Catalog endpoints were dead (no way for a client to obtain a `media_id`). This enables the standard SPA pre-upload flow. Catalog's inline upload (passing a file directly on the Catalog write endpoints) is unchanged.

---

### Catalog Module — Permission-based Authorization Refactor

**Replaced role-based middleware with granular Spatie-permission policies across the Catalog module.**

#### Changed
- **Removed** `RequireAdminRole` middleware and the `catalog.admin` route alias — authorization no longer checks `isAdmin()` or the admin role directly.
- **Added** three policy classes (`CategoryPolicy`, `ProductPolicy`, `ProductVariantPolicy`) in `Modules\Catalog\Domain\Policies\`. Each method delegates to `$user->can('catalog.X.Y')` and is typehinted against `Illuminate\Contracts\Auth\Access\Authorizable` to respect the Identity module boundary.
- **Added** `CatalogAuthServiceProvider` which registers the policies; booted from `CatalogServiceProvider::register()`.
- **Added** 11 catalog permissions to `RolesAndPermissionsSeeder`: `catalog.category.{create,update,delete}`, `catalog.product.{view-admin,create,update,delete}`, `catalog.variant.{create,update,delete}`. Admin role receives all automatically.
- **Updated** all 6 write `FormRequest` classes (`Store*/Update*`) — `authorize()` now checks the specific permission, ensuring 403 is always returned before validation (never a 422 bleed-through).
- **Updated** controller destroy/showAdmin methods to use `$this->authorize()` via `AuthorizesRequests` trait.
- **Updated** `CatalogAuthorizationTest` with two new tests proving authorization is permission-based (a user with a specific permission can act without the admin role; the same user is still forbidden on other actions).

---

### Catalog Module (v1.0.0)

**Complete rewrite of the storefront product catalog system as a modular, fully-tested DDD/Hexagonal monolith component.**

#### New Features
- **Categories:** Infinite-depth hierarchical categories with image assets. Read: by ID, list active roots (paginated). Write: create, update, delete.
- **Products:** Product shells with draft/published lifecycle, multi-image galleries, and category linking. Read: by ID, by slug, by category (paginated), admin override for draft products. Write: create (uploads primary + gallery in one transaction), update, delete.
- **Product Variants:** Purchasable options (size, color, etc.) with unique SKUs, prices in cents (integers only), per-variant images, and JSON attributes. Exactly one default variant per product enforced at the application layer. Read: by ID, by SKU. Write: create, update, delete.
- **HTTP Layer:** 3 controllers, 8 form requests (including pagination + update validators), 4 API resources.
- **Pagination:** List endpoints return `LengthAwarePaginator` envelopes (data + links + meta). Configurable `per_page` (1–100, default 15) auto-documented by Scramble.
- **Cents Rule Enforcement:** All prices (base_price, compare_at_price) must be integers in cents. Form requests cast whole-number strings to int; decimals are rejected. Actions double-check with `InvalidArgumentException`.
- **Feature Tests:** 29 PHPUnit tests across categories, products, and variants covering CRUD happy paths, validation errors, 404 scenarios, and invariant enforcement.

#### Architecture
- **Domain Layer:** 4 Eloquent models (`Category`, `Product`, `ProductImage`, `ProductVariant`), 4 DTOs, 1 contract interface.
- **Application Layer:** 9 Actions (3 create + 3 update + 3 delete), single-responsibility handlers.
- **Infrastructure Layer:** 3 controllers (dependency-injected), 8 form requests, 4 API resources, 20 RESTful routes.
- **Modular Isolation:** Zero imports of catalog models outside the module. All cross-module communication via `CatalogManagerInterface` and DTOs.
- **Test Coverage:** All data mutations (Actions, Manager methods) have corresponding feature tests with edge cases.

#### Database
```sql
CREATE TABLE categories (
  id, parent_id, name, slug (unique), media_id (loose ref), is_active, timestamps
);

CREATE TABLE products (
  id, category_id (FK), title, slug (unique), description, status (draft|published), primary_media_id (loose ref), timestamps
);

CREATE TABLE product_images (
  id, product_id (FK cascade), media_id (loose ref), sort_order, timestamps
);

CREATE TABLE product_variants (
  id, product_id (FK cascade), sku (unique), is_default, base_price (int cents), compare_at_price (int cents, nullable), media_id (loose ref), attributes (json), timestamps
);
```

#### API Routes (20 endpoints)
```
POST   /api/v1/catalog/categories              — Create
GET    /api/v1/catalog/categories/roots        — List active root categories (paginated)
GET    /api/v1/catalog/categories/{id}         — Read
PATCH  /api/v1/catalog/categories/{id}         — Update
DELETE /api/v1/catalog/categories/{id}         — Delete

POST   /api/v1/catalog/products                — Create (uploads primary + gallery)
GET    /api/v1/catalog/products/{id}           — Read (published only)
GET    /api/v1/catalog/products/{id}/admin     — Read (any status)
GET    /api/v1/catalog/products/slug/{slug}    — Read by slug (published only)
GET    /api/v1/catalog/categories/{categoryId}/products  — List by category (paginated, published only)
PATCH  /api/v1/catalog/products/{id}           — Update
DELETE /api/v1/catalog/products/{id}           — Delete

POST   /api/v1/catalog/products/{productId}/variants  — Create
GET    /api/v1/catalog/variants/{variantId}   — Read
GET    /api/v1/catalog/variants/sku/{sku}     — Read by SKU
PATCH  /api/v1/catalog/variants/{variantId}   — Update
DELETE /api/v1/catalog/variants/{variantId}   — Delete
```

---

## [v12.12.1](https://github.com/laravel/laravel/compare/v12.12.0...v12.12.1) - 2026-03-10

* [12.x] Makes imports consistent by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6760

## [v12.12.0](https://github.com/laravel/laravel/compare/v12.11.2...v12.12.0) - 2026-03-09

* Update phpunit version to ^11.5.50 to address CVE by [@PerryvanderMeer](https://github.com/PerryvanderMeer) in https://github.com/laravel/laravel/pull/6746
* [12.x] Add `APP_NAME` fallback in mail config by [@apoorvdarshan](https://github.com/apoorvdarshan) in https://github.com/laravel/laravel/pull/6755
* [12.x] Neutralize DB_URL in default phpunit.xml by [@Husseinadq](https://github.com/Husseinadq) in https://github.com/laravel/laravel/pull/6761

## [v12.11.2](https://github.com/laravel/laravel/compare/v12.11.1...v12.11.2) - 2026-01-19

* [12.x] Update composer dev script to ensure no timeout by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6735
* [12.x] Update jobs/cache migrations by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6736
* [12.x] Remove failed jobs indexes by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6739
* [12.x] Add `APP_URL` fallback in filesystems config by [@KentarouTakeda](https://github.com/KentarouTakeda) in https://github.com/laravel/laravel/pull/6742
* chore: Update outdated GitHub Actions version by [@pgoslatara](https://github.com/pgoslatara) in https://github.com/laravel/laravel/pull/6743

## [v12.11.1](https://github.com/laravel/laravel/compare/v12.11.0...v12.11.1) - 2025-12-23

* Use environment variable for `DB_SSLMODE` - Postgres by [@robsontenorio](https://github.com/robsontenorio) in https://github.com/laravel/laravel/pull/6727
* fix: ensure APP_URL does not have trailing slash in filesystem by [@msamgan](https://github.com/msamgan) in https://github.com/laravel/laravel/pull/6728

## [v12.11.0](https://github.com/laravel/laravel/compare/v12.10.1...v12.11.0) - 2025-11-25

* fix: cookies are not available for subdomains by default by [@joostdebruijn](https://github.com/joostdebruijn) in https://github.com/laravel/laravel/pull/6705
* Fix PHP 8.5 PDO Driver Specific Constant Deprecation by [@RyanSchaefer](https://github.com/RyanSchaefer) in https://github.com/laravel/laravel/pull/6710
* Ignore Laravel compiled views for Vite  by [@QistiAmal1212](https://github.com/QistiAmal1212) in https://github.com/laravel/laravel/pull/6714

## [v12.10.1](https://github.com/laravel/laravel/compare/v12.10.0...v12.10.1) - 2025-11-06

* Update schema URL in package.json by [@robinmiau](https://github.com/robinmiau) in https://github.com/laravel/laravel/pull/6701

## [v12.10.0](https://github.com/laravel/laravel/compare/v12.9.1...v12.10.0) - 2025-11-04

* Add background driver by [@barryvdh](https://github.com/barryvdh) in https://github.com/laravel/laravel/pull/6699

## [v12.9.1](https://github.com/laravel/laravel/compare/v12.9.0...v12.9.1) - 2025-10-23

* [12.x] Replace Bootcamp with Laravel Learn by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6692
* [12.x] Comment out CLI workers for fresh applications by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/laravel/pull/6693

## [v12.9.0](https://github.com/laravel/laravel/compare/v12.8.0...v12.9.0) - 2025-10-21

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.8.0...v12.9.0

## [v12.8.0](https://github.com/laravel/laravel/compare/v12.7.1...v12.8.0) - 2025-10-20

* [12.x] Makes test suite using broadcast's `null` driver by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6691

## [v12.7.1](https://github.com/laravel/laravel/compare/v12.7.0...v12.7.1) - 2025-10-15

* Added `failover` driver to the `queue` config comment.  by [@sajjadhossainshohag](https://github.com/sajjadhossainshohag) in https://github.com/laravel/laravel/pull/6688

## [v12.7.0](https://github.com/laravel/laravel/compare/v12.6.0...v12.7.0) - 2025-10-14

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.6.0...v12.7.0

## [v12.6.0](https://github.com/laravel/laravel/compare/v12.5.0...v12.6.0) - 2025-10-02

* Fix setup script by [@goldmont](https://github.com/goldmont) in https://github.com/laravel/laravel/pull/6682

## [v12.5.0](https://github.com/laravel/laravel/compare/v12.4.0...v12.5.0) - 2025-09-30

* [12.x] Fix type casting for environment variables in config files by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6670
* Fix CVEs affecting vite by [@faissaloux](https://github.com/faissaloux) in https://github.com/laravel/laravel/pull/6672
* Update .editorconfig to target compose.yaml by [@fredikaputra](https://github.com/fredikaputra) in https://github.com/laravel/laravel/pull/6679
* Add pre-package-uninstall script to composer.json by [@cosmastech](https://github.com/cosmastech) in https://github.com/laravel/laravel/pull/6681

## [v12.4.0](https://github.com/laravel/laravel/compare/v12.3.1...v12.4.0) - 2025-08-29

* [12.x] Add default Redis retry configuration by [@mateusjatenee](https://github.com/mateusjatenee) in https://github.com/laravel/laravel/pull/6666

## [v12.3.1](https://github.com/laravel/laravel/compare/v12.3.0...v12.3.1) - 2025-08-21

* [12.x] Bump Pint version by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6653
* [12.x] Making sure all related processed are closed when terminating the currently command by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6655
* [12.x] Use application name from configuration by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6655
* Bring back postAutoloadDump script by [@jasonvarga](https://github.com/jasonvarga) in https://github.com/laravel/laravel/pull/6662

## [v12.3.0](https://github.com/laravel/laravel/compare/v12.2.0...v12.3.0) - 2025-08-03

* Fix Critical Security Vulnerability in form-data Dependency by [@izzygld](https://github.com/izzygld) in https://github.com/laravel/laravel/pull/6645
* Revert "fix" by [@RobertBoes](https://github.com/RobertBoes) in https://github.com/laravel/laravel/pull/6646
* Change composer post-autoload-dump script to Artisan command by [@lmjhs](https://github.com/lmjhs) in https://github.com/laravel/laravel/pull/6647

## [v12.2.0](https://github.com/laravel/laravel/compare/v12.1.0...v12.2.0) - 2025-07-11

* Add Vite 7 support by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/laravel/pull/6639

## [v12.1.0](https://github.com/laravel/laravel/compare/v12.0.11...v12.1.0) - 2025-07-03

* [12.x] Disable nightwatch in testing by [@laserhybiz](https://github.com/laserhybiz) in https://github.com/laravel/laravel/pull/6632
* [12.x] Reorder environment variables in phpunit.xml for logical grouping by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6634
* Change to hyphenate prefixes and cookie names by [@u01jmg3](https://github.com/u01jmg3) in https://github.com/laravel/laravel/pull/6636
* [12.x] Fix type casting for environment variables in config files by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6637

## [v12.0.11](https://github.com/laravel/laravel/compare/v12.0.10...v12.0.11) - 2025-06-10

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.0.10...v12.0.11

## [v12.0.10](https://github.com/laravel/laravel/compare/v12.0.9...v12.0.10) - 2025-06-09

* fix alphabetical order by [@Khuthaily](https://github.com/Khuthaily) in https://github.com/laravel/laravel/pull/6627
* [12.x] Reduce redundancy and keeps the .gitignore file cleaner by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6629
* [12.x] Fix: Add void return type to satisfy Rector analysis by [@Aluisio-Pires](https://github.com/Aluisio-Pires) in https://github.com/laravel/laravel/pull/6628

## [v12.0.9](https://github.com/laravel/laravel/compare/v12.0.8...v12.0.9) - 2025-05-26

* [12.x] Remove apc by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6611
* [12.x] Add JSON Schema to package.json by [@martinbean](https://github.com/martinbean) in https://github.com/laravel/laravel/pull/6613
* Minor language update by [@woganmay](https://github.com/woganmay) in https://github.com/laravel/laravel/pull/6615
* Enhance .gitignore to exclude common OS and log files by [@mohammadRezaei1380](https://github.com/mohammadRezaei1380) in https://github.com/laravel/laravel/pull/6619

## [v12.0.8](https://github.com/laravel/laravel/compare/v12.0.7...v12.0.8) - 2025-05-12

* [12.x] Clean up URL formatting in README by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6601

## [v12.0.7](https://github.com/laravel/laravel/compare/v12.0.6...v12.0.7) - 2025-04-15

* Add `composer run test` command by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/laravel/pull/6598
* Partner Directory Changes in ReadME by [@joshcirre](https://github.com/joshcirre) in https://github.com/laravel/laravel/pull/6599

## [v12.0.6](https://github.com/laravel/laravel/compare/v12.0.5...v12.0.6) - 2025-04-08

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.0.5...v12.0.6

## [v12.0.5](https://github.com/laravel/laravel/compare/v12.0.4...v12.0.5) - 2025-04-02

* [12.x] Update `config/mail.php` to match the latest core configuration by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6594

## [v12.0.4](https://github.com/laravel/laravel/compare/v12.0.3...v12.0.4) - 2025-03-31

* Bump vite from 6.0.11 to 6.2.3 - Vulnerability patch by [@abdel-aouby](https://github.com/abdel-aouby) in https://github.com/laravel/laravel/pull/6586
* Bump vite from 6.2.3 to 6.2.4 by [@thinkverse](https://github.com/thinkverse) in https://github.com/laravel/laravel/pull/6590

## [v12.0.3](https://github.com/laravel/laravel/compare/v12.0.2...v12.0.3) - 2025-03-17

* Remove reverted change from CHANGELOG.md by [@AJenbo](https://github.com/AJenbo) in https://github.com/laravel/laravel/pull/6565
* Improves clarity in app.css file by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6569
* [12.x] Refactor: Structural improvement for clarity by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6574
* Bump axios from 1.7.9 to 1.8.2 - Vulnerability patch by [@abdel-aouby](https://github.com/abdel-aouby) in https://github.com/laravel/laravel/pull/6572
* [12.x] Remove Unnecessarily [@source](https://github.com/source) by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6584

## [v12.0.2](https://github.com/laravel/laravel/compare/v12.0.1...v12.0.2) - 2025-03-04

* Make the github test action run out of the box independent of the choice of testing framework by [@ndeblauw](https://github.com/ndeblauw) in https://github.com/laravel/laravel/pull/6555

## [v12.0.1](https://github.com/laravel/laravel/compare/v12.0.0...v12.0.1) - 2025-02-24

* [12.x] prefer stable stability by [@pataar](https://github.com/pataar) in https://github.com/laravel/laravel/pull/6548

## [v12.0.0 (2025-??-??)](https://github.com/laravel/laravel/compare/v11.0.2...v12.0.0)

Laravel 12 includes a variety of changes to the application skeleton. Please consult the diff to see what's new
