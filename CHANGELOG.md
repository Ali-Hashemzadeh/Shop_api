# Release Notes

## [Unreleased](https://github.com/laravel/laravel/compare/v12.12.1...12.x)

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
