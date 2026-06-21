# Release Notes

## [Unreleased](https://github.com/laravel/laravel/compare/v12.12.1...12.x)

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

Laravel 12 includes a variety of changes to the application skeleton. Please consult the diff to see what's new.
