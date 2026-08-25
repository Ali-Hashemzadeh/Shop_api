# Project Context & Architecture Brief: Modular Monolith Commerce Backend

This document serves as the master source of truth for the application architecture, strict engineering guidelines, module ledger, and current development sprint. Any agent acting on this codebase must adhere strictly to these paradigms without deviation.

---

## 1. Architectural Philosophy: The Modular Monolith

To ensure extreme scalability without the DevOps overhead of microservices, this system is built as a **Modular Monolith**.

### The Core Enclosure Rules
1. **Hard Module Isolation:** Each folder inside `Modules/` must function as an independent service.
   2. **No Model Sharing:** A module is strictly forbidden from importing Eloquent models, listening to internal events, or running raw database joins against another module's database tables.
   3. **Cross-Module Communication:** All interaction between modules must happen exclusively across the domain wall using **Contract Interfaces** and immutable **Data Transfer Objects (DTOs)** resolved from Laravel's Service Container.
   4. **Internal Directory Blueprint:** Each module mirrors a clean DDD/Hexagonal layout:
```text
Modules/
└── [ModuleName]/
    ├── Domain/
    │   ├── Models/       # Internal Eloquent models (Encapsulated)
    │   ├── Contracts/    # Public Interface Contracts for other modules
    │   └── DTOs/         # Immutable data carriers crossing the border
    │   └── Policies/         # policies of the modules
    ├── Application/
    │   └── Actions/      # Single-responsibility business logic handlers
    └── Infrastructure/
        ├── Http/         # Controllers, Request Validators, API Resources
        ├── Persistence/  # Migrations, Repositories, Seeders
        └── Providers/    # Module-specific Service Providers
        └── Routes/    # Module-specific routes
```

---

## 2. Inviolable Engineering Rules

* **Financial Integrity (The Cents Rule):** All monetary values (prices, discounts, taxes) must be processed and stored in the database exclusively as raw integers representing the smallest currency unit (e.g., its persian numbers so keep that in mind). Floating-point numbers are completely barred for financial attributes.
  * **Loose Media Coupling:** To keep modules completely decoupled, tables outside the Media module must **never** use cascading database foreign keys pointing to the `media` table. Instead, store them as standard `unsignedBigInteger('media_id')->nullable()` columns.
  * **Test-Driven Modifications:** Any block that mutates data state (Actions, Repositories, Managers) must have matching Feature/Unit tests covering success and failure edge-cases.
  * **Storage Environment:** The application relies 100% on fast, streamlined local filesystem storage using Laravel's native `Storage` facade mapping to the `public` disk stream via symlink. Database records do not track target storage drives.

---

## 2b. Customer-Facing Public Codes (cross-cutting)

Every entity a customer might read aloud, quote to support, or search for carries a short code shaped
`<namespace><segment>-<SUFFIX>` — e.g. `bdo-Q8M2XC`. This is **display, search, and support only**; it
replaces no primary key, foreign key, relation, cron input, internal query, or authorization rule.

* **One generator, no scattered prefixes.** `App\Support\PublicCodeGenerator` is the only place a code is
  assembled. `App\Support\PublicCodeEntity` is the only place entity → segment is mapped
  (`p`/`v`/`o`/`t`/`s`/`a`/`c`; Payment is `t` because `p` belongs to Product). Never write `'bdp'` or
  `'bdo'` as a literal anywhere else.
* **Namespace is required configuration.** `config/public_codes.php` ← `PUBLIC_CODE_NAMESPACE` (no
  fallback — a missing/invalid value throws `PublicCodeGenerationException`, because a public identifier
  cannot be un-issued). Normalized to lowercase. Changing it affects **new** records only.
* **Suffix:** exactly six CSPRNG characters from `23456789ABCDEFGHJKMNPQRSTUVWXYZ` (no `0`/`O`/`1`/`I`/`L`).
  Never derived from the primary key, a hash, a timestamp, or a sequence. Length and alphabet are class
  constants, not config.
* **Uniqueness is two-layered and module-owned.** `App\Support\HasPublicCode` gives each model a `creating`
  hook, `generateUniquePublicCode()` (bounded retry, checks **only that model's own table** — never another
  module's), and `createWithPublicCode()`, which assigns the code itself (the hook is muted under
  `WithoutModelEvents`, so seeders need this) and retries a duplicate-key violation **naming the code column
  specifically**; every other `QueryException` propagates untouched. The unique index is the final authority.

| Entity | Format | Column | Note |
|---|---|---|---|
| Product | `bdp-XXXXXX` | `products.uuid` | reused; response `id` |
| Product variant | `bdv-XXXXXX` | `product_variants.sku` | reused; the cross-module key Cart/Inventory/Order already exchange |
| Order | `bdo-XXXXXX` | `orders.public_code` | new |
| Payment | `bdt-XXXXXX` | `payments.public_code` | new; **not** a gateway reference — `transaction_reference` is untouched |
| Shipment | `bds-XXXXXX` | `shipments.public_code` | reused; replaces `SH-*` generation |
| Address | `bda-XXXXXX` | `addresses.public_code` | new |
| Category | `bdc-XXXXXX` | `categories.public_code` | new |
| Review | `bdr-XXXXXX` | `reviews.uuid` | new; column name historical like Product |

* **Immutable and server-owned.** Never accepted from client input, never a writable field, never regenerated
  on update, never reused after deletion. The four new columns are **nullable at the DB level** (matching the
  `products.uuid` precedent — tightening to NOT NULL on SQLite rebuilds the table and can drop indexes); the
  application layer guarantees a value and the unique index enforces it.
* **Legacy identifiers were never rewritten.** 7-char hex product UUIDs, older variant SKUs, and `SH-*`
  shipment codes all still resolve. Product routes use `PublicCodeGenerator::routePattern()` →
  `(legacy-hex | bdp-XXXXXX)`, which still excludes `admin`/`slug`; shipment routes' `[A-Za-z0-9\-]+` already
  spans both. **Do not narrow either pattern.** Route caching bakes the namespace in — `route:clear` after a
  namespace change.
* **Search/detail:** exact, whole-string, case-normalized lookups on the unique index — never `LIKE`, never partial
  (or records could be enumerated by prefix). Public product list matches `bdp-`/`bdv-` (variant returns its
  owning product); public category list matches `bdc-` at any depth; `GET /orders` and `GET /addresses` match
  their code **always intersected with the caller's own rows**, so another user's real code returns a page
  indistinguishable from a nonexistent one. Customer `GET /addresses/{publicCode}` also resolves an exact normalized
  `bda-XXXXXX` (numeric GET → 404) before applying the existing Address policy; mutations, checkout, and admin routes
  remain numeric. Public-code search is deliberately **not** added to any admin list.
* **Notifications:** integration events carry `orderPublicCode` **alongside** the numeric `orderId` (listeners
  still need the id for deep links); events stay primitives-only. Customer-facing SMS/in-app copy quotes the
  code, but the SMS **parameter name** stays `OrderId` — it is the provider-side template variable.
* Tests: `tests/Unit/Support/PublicCodeGeneratorTest.php` (12) + `tests/Feature/PublicCode/PublicCodeTest.php` (26).

---

## 3. Current Module Ecosystem Ledger

### 🔒 1. Identity Module (Status: Active & Complete)
* **Responsibility:** OTP + password authentication, user profile management, shipping location matrices, and user addresses.
  * **Key Entities:** `User`, `Address`, `Province`, `City`.
  * **State:** Fully functional, using decoupled Eloquent repositories bound to service contracts (`UserRepositoryInterface`, `AddressRepositoryInterface`).
  * **Authentication — split-auth onboarding (OTP + optional password, phone-based, unified register+login):**
      * Schema (`2026_06_15_120000_refactor_users_table_for_otp_auth`): `password` and `name` made nullable; added `otp_code` (stored **hashed**, hidden), `otp_expires_at` (datetime cast), and a loose `media_id` (FK-free profile image, per Media coupling rule). `2026_06_27_120000_ensure_users_password_nullable` re-affirms `password` nullability (defensive, idempotent no-op on the current schema). `User` casts `password` to `hashed` and hides it.
      * `POST /api/v1/auth/check-user` — body `phone_number` (required, `09xxxxxxxxx`), `throttle:public`. Returns `200 {is_new_user, allowed_methods}`. Unknown phone → `{true, ["otp"]}` (forces OTP to prove ownership before a password path opens); known phone → `{false, ["password", "otp"]}`. Action: `CheckUserStatus`.
      * `POST /api/v1/otp/request` — body `phone` (required, `09xxxxxxxxx`), optional `name` and `last_name`. Finds the user by phone or **creates one on first contact** (assigns `customer` role, persisting any supplied `name`/`last_name`; sign-up == login). Generates a numeric code (`identity.otp.length`, default 5), stores its hash with a TTL (`identity.otp.ttl_minutes`, default 2), and dispatches it. Returns `200 {message, expires_in, is_new_user}`.
      * `POST /api/v1/otp/verify` — body `phone`, `code`, `device_name`. Validates code presence, expiry, and `Hash::check`; on success consumes the code (cleared, single-use, replay-safe) and mints a Sanctum token. Verify only proves phone ownership — it no longer accepts `name`/`password`; a display name is captured at OTP request and a password is set later via the authenticated set-password endpoint. Returns `200 {message, user, token}`. Failure → `422` on `code`.
      * `POST /api/v1/auth/set-password` — authenticated (`auth:sanctum`, `throttle:api`). Body `password` (required, 8–255, `confirmed`) + matching `password_confirmation`. Sets or replaces the caller's password (hashed via `Hash::make`); ownership is proven by the Sanctum token, so no current password is required. Returns `200 {message, user}`. Guests → `401`; mismatched confirmation → `422`. Action: `SetPassword`.
      * `POST /api/v1/auth/login-password` — body `phone_number`, `password`, optional `device_name`, `throttle:otp` (strict per-IP brute-force limiter). Finds the user by phone and verifies via `Hash::check`. Unknown phone, password-less account, and wrong password all return the **same generic `401 {message: "Invalid credentials."}`** (no account-existence leak). Success → `200 {message, user, token}`. Action: `LoginWithPassword` (throws `HttpException(401)`).
      * **Delivery boundary:** `Modules\Identity\Domain\Contracts\OtpSenderInterface::send(phone, code)`. Bound in `IdentityServiceProvider` to `LogOtpSender` (Infrastructure\Services) — a **log-only placeholder** until the SMS web service is wired in. Swap the binding for a real gateway without touching the flow.
      * **Actions:** `CheckUserStatus`, `RequestOtp`, `VerifyOtp`, `SetPassword`, `LoginWithPassword` (Application\Actions). All DB access goes through `UserRepositoryInterface` — no model leaks across the module boundary.
  * **Roles — `customer`, `admin`, `delivery`.** Roles are permission bundles, nothing more. **A delivery worker always holds `customer` *and* `delivery`:** a courier is a shopper who also delivers, so the `delivery` role carries only the two extra fulfillment permissions (granted by `ShipmentPermissionsSeeder`) and **never duplicates the customer bundle**. `RolesAndPermissionsSeeder` creates the role empty and deliberately does **not** `syncPermissions` it — syncing would wipe Shipment's grants on every reseed. There is no `user` role; the shopper role is `customer`.
  * **Admin user management:**
      * `POST /api/v1/admin/users` — `profile.create-any`, plus `profile.assign-delivery` when `role=delivery` (so the create permission alone is not an escalation path around the grant endpoint). Body `name` (required), `last_name?`, `phone` (required, `09xxxxxxxxx`, unique), `email?` (unique), `role` ∈ {`customer`, `delivery`} — **`admin` is not creatable**. Assigns `customer`, and additionally `delivery` for the courier case. **No password and no temporary secret is minted:** the phone is the credential, the account signs in through the existing OTP flow, and the owner may add a password later via the authenticated set-password endpoint. Returns `201 {message, data}`. Request: `StoreUserRequest`; Action: `CreateUserByAdmin`.
      * `POST /api/v1/admin/users/{user}/delivery-role` — `profile.assign-delivery`. Additive and **idempotent**: adds `delivery`, ensures `customer`, preserves every other role. A user with **no phone** is refused with **422** on `phone` (unreachable by assignment SMS, and unable to sign in). Request: `GrantDeliveryRoleRequest`; Action: `GrantDeliveryRole`.
      * `GET /api/v1/admin/users?role=delivery` — role filter (`admin`|`customer`|`delivery`) on `UserRepositoryInterface::paginate(int $perPage, ?string $role)`, which is how an admin picks a driver. `UserResource` exposes `roles`; `AuthUserResource` (`GET /me`) exposes `roles` **and** `permissions` — additive capability info, because authorization is permission-based and a role name alone does not tell a client what it may do.
  * **Public Cross-Module Contract:**
      * `Modules\Identity\Domain\Contracts\IdentityManagerInterface`: exposes `isAdmin(int $userId): bool`. Available for cross-module role checks, but **prefer direct permission checks via `$user->can('...')` in policies instead** — see Authorization Pattern below.
      * `getUserSummary(int $userId): UserSummaryDTO` — returns `{id, name, lastName, phone, email}` (`Domain/DTOs/UserSummaryDTO.php`). Consumed by Order's `CreateOrderAction` to build the immutable `customer_snapshot` at checkout; never leaks the `User` model across the boundary.
      * `isDeliveryUser(int $userId): bool` — the smallest primitive Shipment needs before assigning a delivery. Shipment asks this question instead of reading roles or importing the `User` model. Paired with `getUserSummary()->phone` for the "must be reachable" half of the rule.
      * `getDeliveryUserIds(): array` — sibling of `getAdminUserIds()`; ids only. Used by `ShipmentSampleDataSeeder` to find a demo courier without touching Identity's tables.
      * `getOwnedAddressSnapshot(int $userId, int $addressId): ?AddressSnapshotDTO` — a frozen copy of one address, **only** when it belongs to that user; `null` covers both "missing" and "someone else's" so an id cannot be probed for existence. Province/city names are resolved inside Identity, and the DTO carries `latitude`/`longitude`/`map_address` alongside the postal fields. `AddressSnapshotDTO::toArray()` owns the frozen snapshot shape. This replaced Shipment's raw `DB::table('addresses')` lookup — the shipment address snapshot now crosses the wall as a DTO.
      * Concrete: `EloquentIdentityManager` (bound in `IdentityServiceProvider::register()`). Internally calls `User::find()->hasRole('admin')` / `User::findOrFail()->…` — all Spatie internals stay inside Identity.
  * **Route structure:** user-facing address routes registered under `prefix('addresses')` (plural). Customer `GET /addresses/{publicCode}` uses the exact normalized `bda-XXXXXX` code; PATCH/DELETE/default-shipping, checkout `address_id`, and admin address routes remain numeric. Admin user management uses `prefix('admin/users')`. Profile self-service uses `prefix('profile')`.
  * **Known fix applied:** `UpdateAddressRequest` had `city_id` as `required` instead of `sometimes` — corrected so PATCH requests can update partial fields without supplying city.
  * **Address map pin:** `addresses` carries `latitude`/`longitude` (`decimal(10,7)`) and a nullable `map_address` text line (the map's reverse-geocoded string, distinct from the user-typed `address`). Columns are nullable at the DB level, but `StoreAddressRequest` requires `latitude`/`longitude` (`numeric`, `between:-90,90` / `between:-180,180`) on create; `UpdateAddressRequest` treats all three as `sometimes`. `map_address` is always optional. Exposed on `AddressResource`.
  * **Profile:** `User` carries a nullable `last_name` (migration `2026_07_07_000001_add_last_name_to_users_table`) alongside `name`, both mass-assignable. `last_name` is settable at OTP registration and via profile update (`PATCH /api/v1/profile` / admin `PATCH /api/v1/admin/users/{user}`), and is exposed on `UserResource` and `AuthUserResource`.
  * **Permissions (delivery-related):** `profile.create-any` (admin user creation) and `profile.assign-delivery` (granting delivery responsibility) — both seeded to `admin` only, never to `customer` or `delivery`.
  * **Test suite:** `AddressTest` (21 — includes map-pin required/range/nullable/update coverage), `ProfileTest` (9 — includes `last_name` view + update), `AuthControllerTest` (10 — full OTP request/verify matrix: create-on-request incl. `last_name`, no-duplicate, invalid phone, verify+token, wrong/expired/unknown code, single-use replay, me, logout), `PasswordAuthTest` (15 — check-user new/existing/invalid, OTP registration leaves password null + verify ignores password, authenticated set-password hash storage/login/short/missing/guest-401, password login success/wrong-password/unknown-phone/password-less/missing-password), `RolePermissionTest`, `DeliveryRoleTest` (12 — role exists with the right permission set, admin creates customer/delivery, OTP login still works for an admin-created account, `admin` not creatable, grant preserves roles + idempotent, no-phone refusal, `?role=` filter, 401/403 matrix, create-permission-alone cannot mint a courier, `/me` exposes roles+permissions) — all passing except the pre-existing `ProfileTest::authenticated_user_can_update_profile`.

### 📁 2. Media Module (Status: Active & Complete)
* **Responsibility:** Lightweight, high-performance physical file uploads and tracking ledger.
  * **Key Interfaces & Artifacts:**
      * `Modules\Media\Domain\Contracts\MediaManagerInterface`: The only entry point used by other modules to handle files. Methods: `upload(UploadedFile, string $folder): MediaDTO`, `getMedia(int): ?MediaDTO`, `getMediaCollection(array): Collection`, `delete(int): bool`.
      * `Modules\Media\Domain\DTOs\MediaDTO`: The immutable object returned containing the absolute accessible public URL via `Storage::url()`.
      * `Modules\Media\Infrastructure\Persistence\Repositories\LocalMediaManager`: Concrete implementation executing local disk file saves and tracking log generation.
  * **HTTP Endpoints (added):**
      * `POST /api/v1/media` — standalone file upload. Body: `file` (required, image, max 4096 KB) + optional `folder` string (alphanumeric/hyphens/slashes, defaults to `uploads`). Returns `201 {id, url, mime_type, file_size, original_name}`. Requires `media.upload` permission.
      * `DELETE /api/v1/media/{id}` — deletes physical file + ledger row. Returns `204` or `404`. Requires `media.delete` permission.
  * **Authorization:**
      * `MediaPolicy` (`Domain\Policies\`) — `upload()` and `delete()` delegate to `$user->can('media.upload')` / `$user->can('media.delete')`. Typehinted against `Authorizable`, never imports Identity's `User`.
      * `MediaAuthServiceProvider` (`Infrastructure\Providers\`) — registers the policy; booted from `MediaServiceProvider::register()`.
      * `MediaPermissionsSeeder` (`Infrastructure\Persistence\Seeders\`) — seeds `media.upload` and `media.delete`, both granted to the `admin` role.
  * **Inline upload pattern (unchanged):** Catalog's write actions (`CreateCategoryAction`, `CreateProductAction`, `CreateProductVariantAction`, and their Update counterparts) still accept a file directly and call `MediaManagerInterface::upload()` internally. The standalone endpoint enables the *pre-upload* SPA flow (upload → get `media_id` → pass to catalog endpoint) and makes the `media_id` / `primary_media_id` link inputs on Catalog endpoints usable.
  * **Test suite:** `tests/Feature/Media/MediaUploadTest.php` — 12 tests covering 401/403 boundaries, happy-path upload + custom folder + storage assertions, validation (no file, non-image, path-traversal folder), delete (204 + file gone, 404 on unknown), and permission-not-role proof. No file-size cap is enforced at the application layer (server php.ini / nginx limits apply instead).

### 🏷️ 3. Catalog Module (Status: COMPLETE — Steps 1–7 Finished)
* **Responsibility:** Control storefront presentation layout including infinite hierarchical categories, parent products, multi-image product media galleries, and purchasable product variant options.
  * **Schema Layout (Step 2):**
      * `categories`: Supports nesting (`parent_id`) and holds a loose asset reference (`media_id`).
      * `products`: High-level presentation shell with operational tracking (`status: draft, published`) and a main thumbnail (`primary_media_id`). Carries a unique, server-generated `uuid` that is the **public identifier** in the API (routes + response `id`); the integer primary key stays internal and remains the FK target for variants/images.
      * `product_images`: Pivot table supporting **multiple gallery images per product** with a custom display sequence mapping (`sort_order`, `media_id`).
      * `product_variants`: Houses concrete purchasable inventory details tracking unique `sku`, the regular-price currency integer `base_price` (**the only price Catalog stores** — `compare_at_price` was dropped when the Promotion module took over promotional pricing), attributes JSON arrays, a **per-variant image** (`media_id`), and a **`is_default` boolean** marking exactly one variant per product as the storefront fallback. The single-true invariant is enforced at the application layer.
  * **Domain Models (Step 3):**
      * `Category`, `Product`, `ProductImage`, `ProductVariant` models declared with clean internal relationships. `ProductVariant` casts `is_default` → boolean, `base_price` → integer (Cents Rule), `attributes` → array. Read responses additionally carry the live `effective_price` + `discount` computed by the Promotion module — never stored on the variant.
  * **DTOs & Contracts (Step 4):**
      * `CategoryDTO`, `ProductImageDTO`, `ProductVariantDTO` (includes `isDefault: bool`, `basePrice: int`, `compareAtPrice: ?int`), `ProductDTO` (composes image and variant DTO arrays).
      * `CatalogManagerInterface`: full read/write surface. Write: `createCategory`, `updateCategory`, `deleteCategory`, `createProduct`, `updateProduct`, `deleteProduct`, `addProductImage`, `createProductVariant`, `updateProductVariant`, `deleteProductVariant`, `syncSalesCounts` (absolute per-SKU best-seller tally, pushed in from the Order module). Read: `findProduct`, `findProductBySlug`, `findProductAdmin`, `findVariant`, `findVariantBySku`, `getProductsByCategory` (paginated), `getActiveRootCategories` (paginated).
      * `EloquentCatalogManager`: concrete implementation. Uses `getMediaCollection()` for batch URL hydration. Supports pagination on list endpoints via `LengthAwarePaginator`. Bound in `CatalogServiceProvider::register()`.
  * **Application Actions (Step 5):**
      * Create triplet: `CreateCategoryAction`, `CreateProductAction`, `CreateProductVariantAction` handle creation and enforce invariants (Cents Rule, is_default single-true).
      * Update triplet: `UpdateCategoryAction`, `UpdateProductAction`, `UpdateProductVariantAction` handle partial updates with file uploads and invariant enforcement.
      * Delete triplet: `DeleteCategoryAction`, `DeleteProductAction`, `DeleteProductVariantAction` are thin wrappers.
  * **HTTP Layer (Step 6):**
      * 3 Controllers: `CategoriesController`, `ProductsController`, `ProductVariantsController`.
      * 9 Form Requests: `StoreCategoryRequest`, `UpdateCategoryRequest`, `IndexCategoriesRequest`, `StoreProductRequest`, `UpdateProductRequest`, `IndexProductsRequest`, `IndexAdminProductsRequest`, `StoreProductVariantRequest`, `UpdateProductVariantRequest`.
      * 4 API Resources: `CategoryResource`, `ProductResource`, `ProductImageResource`, `ProductVariantResource` — all accept DTOs, no Eloquent models.
  * **Routes & Feature Tests (Step 7):**
      * 21 RESTful routes: POST/GET/PATCH/DELETE for categories, products, variants.
      * Pagination: `getActiveRootCategories`, `getProductsByCategory`, `getProducts`, and `getProductsAdmin` return `LengthAwarePaginator` (15 items/page, 1–100 configurable via `per_page` query param, `page` for page number). Scramble auto-documents both params.
      * **Hierarchical category product filtering:** Filtering products by `category_id` (`/products`, `/categories/{id}/products`, `/products/admin`, `/campaigns/{slug}/products`) resolves the selected category and all of its descendants recursively at any depth via `CategoryHierarchy::descendantsOf()` before SQL pagination (`whereIn('category_id', $categoryIds)`). Ancestors and siblings are excluded. Invalid categories return 422 via FormRequest validation before expansion.
      * **Product sort (`?sort=`):** `/products`, `/categories/{id}/products`, and `/products/admin` accept `cheapest` / `most_expensive` (order by the **default variant's** `base_price` via correlated subquery) and `most_sold` (order by the denormalized, indexed `products.sales_count`; exposed as `sales_count`, never client-accepted). Absent/invalid → newest-first default (invalid → 422). `sales_count` is kept in sync by the **Order** module's hourly `orders:sync-sales-counts` command, which aggregates realized orders (`OrderStatus::soldStatuses()` = paid/processing/shipped) within its own tables and pushes an absolute per-SKU tally through `CatalogManagerInterface::syncSalesCounts()` — Catalog resolves SKU→variant→product internally, honoring the no-cross-module-join rule.
  * **Authorization Layer (Step 8 — Permission-based Policies):**
      * **Public routes** (no auth): all GET read endpoints (category list/show, product show/by-slug/by-category, variant show/by-sku).
      * **Protected routes** (`auth:sanctum` only on the route): all write operations (POST/PATCH/DELETE), `GET /products/admin` (all-status admin index), and `GET /products/{uuid}/admin`. `auth:sanctum` gives 401 for unauthenticated; policies give 403 for unauthorized. All product-level routes are addressed by the product's `uuid` and carry a `whereUuid` constraint, so numeric ids 404 and `/products/{uuid}` never shadows `/products/admin`. (Variants and images stay addressed by integer id / SKU.)
      * **Brands:** flat lookup (`brands` table: `name`, unique `slug`, loose `media_id`, `is_active`) that products optionally belong to via a nullable `products.brand_id` FK (`nullOnDelete` — deleting a brand unlinks its products). Public reads `GET /catalog/brands` (paginated, `search` on name) + `GET /catalog/brands/{id}`; admin writes `POST`/`PATCH`/`DELETE /catalog/brands/{id}` behind `auth:sanctum` + `catalog.brand.*`. Logo via inline `image` upload OR pre-uploaded `media_id` (mutually exclusive via `prohibits`); `BrandDTO`/`BrandResource` expose the resolved `image_url`. Products carry `brand_id` on read, accept it on create/update, and the list/admin endpoints filter by `brand_id`; free-text `search` also matches brand name (`orWhereHas('brand')`). Contract methods on `CatalogManagerInterface`: `findBrand`, `getBrands`, `createBrand`, `updateBrand`, `deleteBrand`.
      * **Policy files** (`Modules\Catalog\Domain\Policies\`): `CategoryPolicy`, `BrandPolicy`, `ProductPolicy`, `ProductVariantPolicy`. Each method delegates to `$user->can('catalog.X.Y')`. Typehinted against `Illuminate\Contracts\Auth\Access\Authorizable` — **never** import `Modules\Identity\Domain\Models\User` across the module boundary.
      * **`CatalogAuthServiceProvider`** (`Modules\Catalog\Infrastructure\Providers\`) registers all three policies via `$policies` + `registerPolicies()`. It is booted from `CatalogServiceProvider::register()` via `$this->app->register(CatalogAuthServiceProvider::class)`.
      * **Authorization split**: `FormRequest::authorize()` handles store/update and the admin index (`IndexAdminProductsRequest` checks `catalog.product.view-admin`), all running before validation → always 403, never 422, for unauthorized users. `$this->authorize()` in controllers handles destroy and showAdmin (no FormRequest involved). Controllers use the `AuthorizesRequests` trait.
      * **Permissions** seeded in `CatalogPermissionsSeeder`: `catalog.category.{create,update,delete}`, `catalog.brand.{create,update,delete}`, `catalog.product.{view-admin,create,update,delete}`, `catalog.variant.{create,update,delete}`. Admin role receives all permissions automatically (syncs all). Customer role receives none of these.
      * Authorization is **permission-based, not role-based** — any user granted a specific permission can perform that action, independent of role.
  * **Test Suite (Step 9 — Final):**
      * 4 feature test classes: `CategoriesTest`, `ProductsTest`, `ProductVariantsTest`, `CatalogAuthorizationTest` — **128 tests total**.
      * Full CRUD coverage: all create, update, delete, and read actions tested with happy paths, validation failures, 404 scenarios, and invariant enforcement (Cents Rule, is_default single-true, slug uniqueness).
      * Authorization matrix tested in `CatalogAuthorizationTest`: unauthenticated → 401, customer → 403, public routes → 200/404 (never 401/403), plus two permission-not-role proof tests.
      * Dead code removed: `updateVariantPrice` eliminated from `CatalogManagerInterface` and `EloquentCatalogManager` (superseded by `updateProductVariant`).

### 📦 4. Inventory Module (Status: Active & Complete)
* **Responsibility:** Atomic stock tracking, reservation lifecycle (reserve → commit / release), and append-only audit ledger.
  * **Tables:**
      * `inventory_stocks`: `id`, `sku` (unique + indexed), `quantity` (int), `reserved_quantity` (int), timestamps. No FK to Catalog — sku is the natural key.
      * `inventory_ledger_entries`: append-only audit log. `id`, `sku`, `type` (enum: `restock`, `sale`, `allocation`, `release`, `adjustment`, `return`), `quantity_change` (signed int), `reference_type` (nullable string), `reference_id` (nullable bigint), `notes` (text nullable), `created_at` only (`UPDATED_AT = null` — rows are never mutated).
  * **Domain Models (internal):** `InventoryStock`, `InventoryLedgerEntry`.
  * **Public Contract:** `Modules\Inventory\Domain\Contracts\InventoryManagerInterface`:
      * `getStockBySku(string $sku): InventoryStockDTO` — throws `StockNotFoundException` for unknown SKUs.
      * `getBatchStockBySkus(array $skus): array` — `array<string, InventoryStockDTO>` keyed by SKU; unknown SKUs silently absent.
      * `adjustStock(sku, quantityChange, type, refType?, refId?, notes?): InventoryStockDTO` — creates record on first call, appends ledger entry.
      * `reserveStock(sku, quantity, orderId): bool` — throws `InsufficientStockException` when available < requested.
      * `commitReservation(sku, quantity, orderId): bool` — deducts from physical + reserved (order fulfilled).
      * `releaseReservation(sku, quantity, orderId): bool` — decrements reserved only (order cancelled).
  * **DTO:** `InventoryStockDTO` — `sku`, `availableQuantity` (quantity − reserved_quantity), `physicalQuantity`, `reservedQuantity`.
  * **Exceptions:** `StockNotFoundException`, `InsufficientStockException` (both in `Domain/Exceptions/`).
  * **CRITICAL CONCURRENCY RULE:** Every mutation in `EloquentInventoryManager` wraps in `DB::transaction()` and acquires a row-level pessimistic lock via `lockForUpdate()` to prevent concurrent checkout race conditions / oversell.
  * **Application Actions:** `UpdateStockAction` (admin restock/adjustment), `ReserveStockAction`, `CommitReservationAction`, `ReleaseReservationAction`.
  * **HTTP Endpoints:**
      * PUBLIC (no auth): `GET /api/v1/inventory/sku/{sku}` — single stock DTO; 404 on unknown. `POST /api/v1/inventory/batch` — body `{skus:[...]}` (max 100); returns object keyed by SKU.
      * ADMIN (`auth:sanctum` + policy): `POST /api/v1/inventory/adjust` — body `{sku, quantity_change (≠0), type: restock|adjustment|return, notes?}`; requires `inventory.stock.manage`. `GET /api/v1/inventory/sku/{sku}/ledger` — paginated audit log (default 15/page); requires `inventory.ledger.view`.
  * **Authorization:** `InventoryPolicy` (typehinted `Authorizable`) — never imports Identity's `User`. `AdjustStockRequest::authorize()` returns 403-before-422. `InventoryAuthServiceProvider` registers policy against `InventoryStock::class`.
  * **Permissions:** `inventory.stock.manage`, `inventory.ledger.view` — both granted to `admin` by `InventoryPermissionsSeeder`.
  * **Test suite:** `InventoryTest` (14) + `InventoryAuthorizationTest` (10) = **24 tests** covering public paths, batch lookup, admin adjust + ledger, reserve/commit/release, oversell prevention, full 401/403/public matrix, and permission-not-role proofs.

### Per-variant Order Quantity Limit (Catalog → Cart → Order)
* Catalog owns nullable `product_variants.max_quantity_per_order` (integer ≥ 1; `null` means no special limit), mutation validation, DTO/resource output, and batch `CatalogManagerInterface::getVariantsBySkus()` lookup.
* Cart add validates resulting quantity; update validates final quantity; both guest and authenticated carts return standard 422 quantity errors. Guest merge clamps against both available Inventory and the Catalog limit. Cart item DTO/resources expose configured/effective maximum, remaining addable quantity, and `quantity_valid` without mutating stale carts.
* Order checkout defensively aggregates by SKU, reloads current Catalog DTOs before any mutation/reservation/Shipment hold, and stores `order_items.max_quantity_per_order_snapshot`. Previous/other/historical Orders are not counted; Inventory remains the independent physical-stock constraint. The Cart is cleared only after successful payment through the established paid callback flow.
### 🛒 5. Cart Module (Status: Active & Complete)
* **Responsibility:** Guest and authenticated shopping cart — add/update/remove items with real-time stock validation, Catalog price enrichment, and session-based guest persistence.
  * **Tables:**
      * `carts`: `id`, `user_id` (nullable bigint, no FK — loose coupling rule), `session_id` (nullable string), timestamps.
      * `cart_items`: `id`, `cart_id` (FK→carts, cascade delete), `sku` (indexed), `quantity` (uint), timestamps. Unique constraint on `(cart_id, sku)`.
  * **Domain Models (internal):** `Cart`, `CartItem`.
  * **DTOs:** `CartItemDTO` (id, cartId, sku, quantity, productName, `basePrice`/`compareAtPrice` as **integers — Cents Rule**, imageUrl, `lineTotal` as integer), `CartDTO` (id, userId, sessionId, items[], itemCount, totalQuantity, `totalPrice` as integer).
  * **Public Contract:** `CartManagerInterface` — `findOrCreateCart(?int, ?string): CartDTO`, `getCart(int): CartDTO`, `addItem(int, string, int): CartDTO`, `removeItem(int, int): CartDTO`, `updateQuantity(int, int, int): CartDTO`, `clearCart(int): void`.
  * **Domain Exceptions:** `CartItemNotFoundException`, `InsufficientStockException`, `ProductSkuNotFoundException`.
  * **Cross-module dependencies (contracts only, zero model imports):**
      * `InventoryManagerInterface::getStockBySku()` — stock validation in `AddToCartAction` and `UpdateCartItemAction`.
      * `CatalogManagerInterface::findVariantBySku()` — price/image enrichment in `EloquentCartManager::buildDTO()`.
  * **Application Actions:** `AddToCartAction` (validates stock first, re-throws Inventory exceptions as Cart-domain exceptions), `GetCartAction`, `UpdateCartItemAction` (validates new quantity against stock), `RemoveFromCartAction`, `ClearCartAction`.
  * **HTTP Endpoints (all behind `cart.identify` middleware):**
      * Middleware: authenticates via `auth('sanctum')` guard (optional) or `X-Session-Id` request header; auto-generates UUID session if neither present. Stores `cart_id` in `$request->attributes`. Returns `X-Cart-Session-Id` response header for guests.
      * `GET /api/v1/cart` — view cart enriched with Catalog pricing.
      * `POST /api/v1/cart/items` — add item `{sku, quantity}`; 422 on zero/missing stock; 201 on success.
      * `PATCH /api/v1/cart/items/{itemId}` — update quantity (stock-validated); 404 on unknown item.
      * `DELETE /api/v1/cart/items/{itemId}` — remove one item; 404 on unknown.
      * `DELETE /api/v1/cart` — clear all items; 204.
  * **Authorization:** No permission gates — cart is self-service; ownership enforced by `CartIdentificationMiddleware`.
  * **Test suite:** `CartTest` — **15 tests, 53 assertions**: guest add/view/clear, authenticated user, guest-vs-auth isolation, stock validation (zero stock → 422, missing inventory → 422), update/remove/404 matrix.

### 📦 6. Order Module (Status: Active & Complete)
* **Responsibility:** Immutable financial contract anchor. Translates a validated cart into a locked order record, atomically reserves inventory, and manages a 15-minute pending-order TTL via a scheduled command.
  * **Tables:**
      * `orders`: `id`, `user_id` (indexed), `status` (string, default `pending`), `total_amount` (int), `shipping_cost` (int, default 0), `tax_amount` (int, default 0), `shipment_method_id` (nullable bigint), `shipping_address` (JSON — snapshotted at creation, immutable), `shipment_snapshot` (nullable JSON), `customer_snapshot` (nullable JSON — `{name, last_name, phone, email}`, captured via `IdentityManagerInterface::getUserSummary()` at checkout; later profile edits never touch it), `transaction_ref` (nullable unique string), `notes` (nullable text), timestamps. Index on `[user_id, status]`.
      * `order_items`: `id`, `order_id` (FK → orders, cascade delete), `sku`, `product_title`, `variant_attributes` (JSON), `product_snapshot` (nullable JSON — `{title, sku, image_url, primary_image_url, attributes}`; `image_url` is the variant image and `primary_image_url` the parent product primary image), `quantity` (int), `max_quantity_per_order_snapshot` (nullable int), `regular_price_per_unit` (Catalog `base_price` at checkout), `automatic_discount_amount_per_unit` (winning Promotion reduction), `automatic_discount_snapshot` (nullable JSON — self-contained record of the winning rule), `price_per_unit` (the effective price actually charged, before any order-level coupon), `compare_at_price` (**legacy**, retained for pre-Promotion orders and always `null` on new ones), `line_total` (int), timestamps. Prices come from the Catalog variants re-read at checkout — which already carry live Promotion pricing — so a discount that ended between the cart page and checkout does not carry into the order. Existing rows/snapshots are never backfilled from current Catalog or Promotion data.
  * **Domain Models (internal):** `Order`, `OrderItem`.
  * **Public Contract:** `Modules\Order\Domain\Contracts\OrderManagerInterface`:
      * `findUserOrderByPublicCode(int $userId, string $publicCode): ?OrderDTO` performs the exact normalized Order-code lookup with ownership in the same query and eager-loads items; missing and foreign-owned codes both return null.
      * `createOrderFromCart(int $userId, int $addressId, int $shipmentMethodId, ?string $notes): OrderDTO` — full checkout orchestration.
      * `markAsPaid(int $orderId, string $transactionRef): OrderDTO` — transitions to `paid`, stores transaction reference.
      * `markAsComplete(int $orderId): OrderDTO` — transitions to `processing`.
      * `getUserOrders(int $userId, int $perPage = 15): LengthAwarePaginator` — paginator items are DTOs (mapped via `->through()`).
      * `findOrder(int $orderId): ?OrderDTO`.
  * **DTOs:** `OrderDTO` (id, userId, status as `OrderStatus` enum, totalAmount, shippingCost, taxAmount, **couponCode**, **couponDiscountAmount**, **couponSnapshot**, **paymentPricingFinalizedAt**, shipmentMethodId, shippingAddress array, shipmentSnapshot, **customerSnapshot**, transactionRef, notes, createdAt, items[]), `OrderItemDTO` (id, orderId, sku, productTitle, variantAttributes, **productSnapshot**, quantity, maxQuantityPerOrderSnapshot, **regularPricePerUnit**, **automaticDiscountAmountPerUnit**, **automaticDiscountSnapshot**, pricePerUnit, compareAtPrice *(legacy)*, lineTotal). `OrderItemResource` is shared by customer and admin detail responses, so both expose the automatic-discount snapshot fields and the unchanged `product_snapshot` object.
  * **Enum:** `OrderStatus: string` — PENDING, PAID, PROCESSING, SHIPPED, CANCELLED, FAILED.
  * **Exceptions:** `EmptyCartException`, `InvalidAddressException` (both in `Domain/Exceptions/`).
  * **`CreateOrderAction`** — constructor deps: `CartManagerInterface`, `CatalogManagerInterface`, `InventoryManagerInterface`, `CancelOrderAction`, `ShipmentManagerInterface`, `IdentityManagerInterface`. Single `DB::transaction()`:
      1. Fetch enriched cart via `CartManagerInterface::getCart()`; validate per-SKU quantity limits against `CatalogManagerInterface::getVariantsBySkus()`.
      2. Resolve the shipment selection snapshot (`ShipmentSelectionDTO::toSnapshot()`) and the customer snapshot (`IdentityManagerInterface::getUserSummary($userId)`, mapped to `{name, last_name, phone, email}`) **before** the transaction opens — both are pure reads.
      3. Cancel any existing pending order for the user + `releaseAndCancel`.
      4. Create `Order` with `shipment_snapshot` + `customer_snapshot`.
      5. Create each `OrderItem` from the Catalog variants re-read for the quantity check: `regular_price_per_unit = basePrice`, `price_per_unit = effectivePrice()`, `automatic_discount_amount_per_unit` + `automatic_discount_snapshot` from the winning Promotion rule, `compare_at_price = null` (legacy), and `product_snapshot` includes `imageUrl` (variant) plus `primaryImageUrl` (parent product). `line_total = effectivePrice × quantity`, and the order subtotal is the sum of those. Then `reserveStock(sku, qty, orderId)`.
      6. `holdForPendingOrder()` on the Shipment contract (local-delivery slot; no-op otherwise).
  * **`CancelOrderAction`** — dep: `InventoryManagerInterface`. Owns the single "release reservations + mark cancelled" primitive (`releaseAndCancel(Order)`, caller-transactional) reused by `CreateOrderAction` (pending replacement) and `CancelExpiredOrdersAction`. `handle(orderId, userId)` is the user-facing cancel: 404 if missing, **403 if the order is not owned by `userId`**, 422 unless status is `pending`; otherwise releases every item's reservation and sets `cancelled` inside a transaction.
  * **`CancelExpiredOrdersAction`** — dep: `CancelOrderAction`. Finds pending orders with `created_at < now() - 15 min` and calls `releaseAndCancel` per order (each wrapped in its own transaction). Run every minute by `orders:cancel-expired` Artisan command scheduled in `routes/console.php`.
  * **`AdminCancelOrderAction`** — dep: `CancelOrderAction`. Admin/operator cancel: no ownership check, but reuses `CancelOrderAction::releaseAndCancel` (no duplicated release/cancel logic). Restricted to `pending` orders only — 404 if missing, 422 if not pending. Paid/shipped cancellation (refund + committed-stock return) is a deliberate future flow, not exposed here.
  * **HTTP Endpoints (customer):**
      * `GET /api/v1/orders/{publicCode}` — authenticated, owner-scoped complete detail by exact Order public code. `GetCustomerOrderDetailAction` composes `OrderDTO`, all customer-safe `PaymentDTO` attempts (newest first), and the complete `ShipmentDTO`/history (or null) into the endpoint-specific `CustomerOrderDetailDTO` and resource. Missing and foreign-owned codes both return 404.
      * `POST /api/v1/orders` — body `{address_id, shipment_method_id, notes?}`; requires `auth:sanctum` + `order.create`; returns 201 OrderResource. 422 on empty cart or invalid address.
      * `GET /api/v1/orders` — paginated order history for the authenticated user; requires `auth:sanctum`; returns paginated OrderResource collection.
      * `POST /api/v1/orders/{order}/cancel` — user cancels **their own** pending order; releases reserved stock and returns 200 OrderResource. `auth:sanctum`; 403 for another user's order, 404 if missing, 422 if not `pending`.
  * **HTTP Endpoints (admin/operator — `AdminOrderController`, view/search/cancel only, no status/create/edit):**
      * `GET /api/v1/admin/orders` — paginated `{data, meta, links}`. `viewAny` policy → `order.view-admin`. Filters (via `IndexAdminOrdersRequest`): `status`, `order_id`, `user_id`, `date_from`, `date_to`, `per_page` (1–100). Rows (`AdminOrderListResource`): id, status, total_amount, created_at, customer summary (from `customer_snapshot` — never re-queries Identity), `item_count`.
      * `GET /api/v1/admin/orders/{order}` — full detail (`AdminOrderResource`). `view` policy → `order.view-admin`. Order fields + customer (from `customer_snapshot`) + items (from `product_snapshot`) + `shipping_address`/`shipment_snapshot` + live `shipment` status **resolved only via `ShipmentManagerInterface::findForOrder`** (null until paid). 404 via route-model binding.
      * `POST /api/v1/admin/orders/{order}/cancel` — admin cancel via `AdminCancelOrderAction`. `cancel` policy → `order.cancel-admin`. Returns 200 detail; 422 if not pending. Order status transitions otherwise belong to Shipment — there is intentionally no status-mutation / create / edit endpoint.
  * **Authorization:** `StoreOrderRequest::authorize()` checks `order.create` → 403 before validation (customer). Customer cancellation is ownership-gated in `CancelOrderAction` (self-service, like Cart). **Admin reads/cancel go through `OrderPolicy`** (`viewAny`/`view` → `order.view-admin`, `cancel` → `order.cancel-admin`), typehinted against `Authorizable`, registered via `Gate::policy(Order::class, OrderPolicy::class)` in `OrderServiceProvider::boot()`.
  * **Permissions:** `order.create`, `order.view-own`, `order.view-admin`, `order.cancel-admin` — admin receives all four; customer receives `order.create` + `order.view-own`.
  * **Test suite:** `OrderTest` — **14 tests**: selling/compare-at price snapshots, distinct variant/product-primary images, selling-price-only order/payment totals, immutable customer/product/price snapshots after Catalog changes, stock reservation, cancellation/TTL/auth matrices. `CustomerOrderDetailTest` — **7 tests**, including the shared OrderItem field set, compare-at serialization, and legacy snapshots without `primary_image_url`. `AdminOrderTest` — **11 tests**, including the same shared item snapshot fields in admin detail.

### 💳 7. Payment Module (Status: Active & Complete)
* **Responsibility:** Hybrid payment processing — cash/offline (`in_person`) and online gateway (`online`) via the Strategy Pattern.
  * **Tables:** `payments`: id, order_id (indexed bigint, no cascade FK), method_type (string), gateway (string nullable), transaction_reference (string unique nullable), amount (int — Cents Rule), status (string), gateway_response (json nullable), timestamps.
  * **Domain Enums:** `PaymentMethodType` (ONLINE, IN_PERSON), `PaymentStatus` (INITIATED, CAPTURED, FAILED, REFUNDED, PENDING_CASH).
  * **Order read contract:** `PaymentManagerInterface::getForOrder(int $orderId): list<PaymentDTO>` returns every attempt ordered by `created_at DESC, id DESC`; the Payment module owns the query.
  * **Contracts:** `PaymentGatewayDriverInterface` (requestPayment, verifyPayment), `PaymentManagerInterface` (`initializePayment(orderId, userId, methodType, gateway?)` — `userId` threads the caller through for the ownership check).
  * **Gateway Drivers (Strategy):** `ZarinpalGatewayDriver` (production — Zarinpal REST API v4), `MockGatewayDriver` (test-only, `shouldVerifySucceed` flag), `PaymentGatewayFactory` (singleton, resolves name → driver via `app()`).
  * **Actions:** `InitializePaymentAction` — **aborts 403 unless the order belongs to the calling `userId`** (checked right after the 404 guard), then in_person → pending_cash + markAsPaid; online → gateway redirect + initiated row. `HandleZarinpalCallbackAction` (idempotency guard, verify, capture/fail, markAsPaid).
  * **HTTP Endpoints:**
      * `POST /api/v1/payments/initialize` — auth:sanctum + throttle:api. Requires `payment.create` **and** ownership of the target order (403 otherwise). Returns `{type, payment_id, status, redirect_url}`.
      * `GET /api/v1/payments/zarinpal/callback` — PUBLIC + throttle:public. The gateway returns here on the **backend** domain; the endpoint still runs server-side verify/capture/`markAsPaid`, then **renders the Blade result page `payment::result`** (HTTP 200 for both success and failure) instead of JSON. Success is derived from the **persisted** Payment status (never from raw `Status`/`Authority` params); page buttons link to the configured frontend (`config('frontend.*')`), and the order-tracking URL is built from the stored Payment's real `order_id`.
  * **View + assets:** `Modules/Payment/Infrastructure/Resources/views/result.blade.php` (self-contained RTL page — breadcrumb + result card, one view for both outcomes driven by `$success`; registered as the `payment` view namespace in `PaymentServiceProvider::boot()` via `loadViewsFrom`). Converted from the storefront template pages `successful-payment.html` / `failed-payment.html`, kept alongside it as design reference. The template's header/footer are deliberately omitted (they need `scripts/app.js` + `swiper.css`, which do not ship with this backend, and link to storefront pages that do not exist on this domain). Page CSS loads from the public disk at `public/modules/payment/app.css` via `asset()`.
  * **Config:** `config/payment.php` — `PAYMENT_DEFAULT_GATEWAY`, `ZARINPAL_MERCHANT_ID`, `ZARINPAL_SANDBOX`. `config/frontend.php` — `url` (`FRONTEND_URL`, falls back to `APP_URL`, trailing slash normalized) + `order_path` (`FRONTEND_ORDER_PATH`, default `orders`). Controller/Blade read these via `config()` only — never `env()`.
  * **Permissions:** `payment.create` — granted to admin + customer.
  * **Cross-module:** `OrderManagerInterface` only (contract boundary, no Order model imported).
  * **Test suite:** `PaymentTest` — **15 tests** covering both flows, callback rendering the Blade page on success/cancel/verify-fail, idempotency, ownership 403, auth matrix, frontend-URL wiring (params can't override the domain), neutral placeholders, no internal-message leakage, and the CSS asset URL.

### 🚚 8. Shipment Module (Status: Active & Complete)
* **Responsibility:** Fulfillment lifecycle from checkout → payment → delivery/postal handoff/pickup. Owns the four fixed, **config-backed** methods, local-delivery working periods + generated dated slots + capacity/reservations, the operational shipment record, method-specific status workflows, and status history.
  * **Fixed methods live in config, not a DB table** (`config/shipment.php`, merged under `shipment`). Codes: `post_standard`, `post_express`, `local_delivery`, `in_person_pickup`. Admin cannot create/rename/reprice them. Prices are integer rials (Cents Rule); pickup is free and address-less. There is **no `shipment_methods` table**.
  * **Tables:** `shipments` (operational record, `public_code` unique customer/admin handle, unique `order_id` → idempotent activation, loose `user_id`/media refs, JSON snapshots, per-status timestamps, plus the delivery-assignment and handoff-code columns below), `shipment_delivery_assignments` (append-only assignment audit: same-module FK `shipment_id`, loose `delivery_user_id`/`assigned_by_user_id`, `assigned_at`, nullable `unassigned_at` — the open row **is** the current assignment), `shipment_status_histories` (append-only, `created_at` only), `delivery_working_periods` (recurring weekly templates), `delivery_slots` (generated dated sessions, unique `[delivery_date, starts_at, ends_at]`, `capacity` + `admin_reserved_capacity`, **no `reserved_count` column**), `delivery_slot_reservations` (source of truth for consumed capacity), `delivery_schedule_exceptions` (`closed` / `custom_hours`). `delivery_date`/times stored as plain strings (`DeliverySlot::dateString()`) — the `date` cast stores `Y-m-d 00:00:00` on SQLite and breaks equality/dedup.
  * **Enums:** `ShipmentMethodType` (postal/local_delivery/pickup), `ShipmentStatus` (pending, preparing, ready_for_post, handed_to_post, ready_for_dispatch, out_for_delivery, delivered, delivery_failed, ready_for_pickup, picked_up, cancelled — with `label()` + `toOrderStatus()`), `DeliverySlotStatus` (open/closed/cancelled), `ReservationStatus` (held/confirmed/released/expired/cancelled/completed; `activeStatuses()` = held+confirmed consume capacity).
  * **Public Contract:** `Modules\Shipment\Domain\Contracts\ShipmentManagerInterface` — `getAvailableMethods`, `getAvailableDeliverySlots`, `validateSelection` (→ `ShipmentSelectionDTO`, throws `ValidationException`), `holdForPendingOrder` (locks slot row, held reservation, local only, returns `?DeliverySlotReservationDTO`), `releasePendingOrder` (idempotent), `activateForPaidOrder` (idempotent by `order_id`, `?ShipmentDTO` — null when no selection), `findForOrder`. Plus `LocalDeliveryEligibilityInterface` — `isEligible(?provinceId, ?cityId)` + `hasServiceArea()` — implemented by `ConfigLocalDeliveryEligibility` (swap the binding for a richer rule).
  * **Service area (env-backed).** `config('shipment.local_delivery.province_ids'|'city_ids')` ← `SHIPMENT_LOCAL_DELIVERY_PROVINCE_IDS` / `SHIPMENT_LOCAL_DELIVERY_CITY_IDS`, comma-separated ids parsed to `list<int>` (blank/non-numeric/≤0 dropped, deduped — ints are required because `isEligible` compares with `in_array(..., true)`). An address matches if **either** list contains it. **Both empty = no service area:** `isEligible` is then permissive for everyone, which is why `hasServiceArea()` exists as a separate question — never infer "inside the zone" from `isEligible` alone.
  * **Postal exclusion.** Inside the service area the store delivers itself, so `post_standard`/`post_express` are withdrawn: reported `available:false` with a reason by `getAvailableMethods`, and rejected by `validateSelection` with a **422** on `shipment_method_code` (hiding without enforcing would leave the rule bypassable). Gated on `hasServiceArea()`, so an unconfigured store still offers post everywhere. Pickup is never affected; a null address is never "inside".
  * **Workflows (method-specific transition maps):** `PostalShipmentWorkflow` (pending→preparing→ready_for_post→handed_to_post, terminal — **never** in_transit/out_for_delivery/delivered), `LocalDeliveryShipmentWorkflow` (…→ready_for_dispatch→out_for_delivery→delivered | delivery_failed→reschedule/retry/cancel), `PickupShipmentWorkflow` (…→ready_for_pickup→picked_up). Resolved by `ShipmentWorkflowResolver`. `ShipmentTransitionService` is the single atomic primitive: lock → assert transition → mutate + timestamp → history → `OrderManagerInterface::syncStatusFromShipment` → reservation complete/release. Invalid transitions throw `InvalidShipmentTransitionException` (renders 422).
  * **Delivery-worker assignment (shipments only, never orders).** `shipments.assigned_delivery_user_id` (indexed, loose Identity reference) + `delivery_assigned_at` say who holds it now; `shipment_delivery_assignments` says who held it and who handed it over. `POST /admin/shipments/{publicCode}/assign-delivery` (`shipment.delivery.assign`, body `delivery_user_id`) → `AssignShipmentDeliveryAction`. Rules, all **422** on `delivery_user_id`: method must be `local_delivery` (postal is handed to a carrier, pickup is collected at the counter); status must not be `delivered`/`cancelled`; the target must satisfy `IdentityManagerInterface::isDeliveryUser()` **and** have a phone. Reassignment is allowed and transactional — close the open history row, open a new one, update the shipment. **Assigning the current assignee is idempotent and silent** (no history row, no notification). `delivery_user_id` is validated with `integer|min:1` and deliberately **not** `exists:users,id` — that would be a Shipment query against Identity's table.
  * **Assignment notification:** primitives-only `ShipmentAssignedToDeliveryEvent` (shipment id + public code, order id + public code, delivery user id, slot date/start/end), dispatched only for a *real* change of assignee. Notification's after-commit `SendShipmentAssignedToDeliveryNotifications` sends the courier db + SMS (`shipment_assigned_delivery`). The message never carries the customer's full address and **never** the handoff code.
  * **Dispatch requires an assignment.** A `local_delivery` shipment may not enter `out_for_delivery` with `assigned_delivery_user_id === null` → **422** on `assigned_delivery_user_id`. Enforced inside `ShipmentTransitionService::transition()` under the same row lock as the transition, so it covers **both** routes in (`ready_for_dispatch → out_for_delivery` and the retry `delivery_failed → out_for_delivery`) rather than one controller. Admin still owns dispatch (`shipment.delivery.dispatch`); couriers have no dispatch permission.
  * **Customer handoff code (local delivery only).** Entering `out_for_delivery` mints a CSPRNG numeric code inside the same transaction (`DeliveryVerificationCodeService`; length `config('shipment.delivery.verification_code_length')`, clamped 4–10, default 6 — never derived from an id, phone, public code, hash, or timestamp). Only `delivery_verification_code_hash` + `delivery_verification_issued_at` are persisted; the column is `$hidden` on the model and appears in no DTO or resource. **The plaintext exists only on the event and in the SMS** — never in the shipment row, shipment history, a stored notification, a log, an assignment record, or any API response.
      * **One SMS, not two.** `ShipmentOutForDeliveryEvent` carries a transient `deliveryCode` (postal uses the separate `ShipmentHandedToPostEvent`, which has none). The stored in-app `shipment_out_for_delivery` notification contains no code and no hash.
      * **Validity is scoped to the current attempt, not a clock.** `→ delivery_failed` clears the hash immediately (the customer's code stops working); the next dispatch mints a fresh one. `→ delivered` consumes the hash and stamps `delivery_verification_verified_at`. Reassignment mid-delivery does **not** regenerate the code — the outgoing courier just loses API access.
  * **Completion is code-gated for everyone.** Driver and admin routes converge on `MarkShipmentDeliveredAction`; only assignment authorization differs (`mustBeAssignedTo` = the driver's own id, or `null` for admin). Under one transaction + row lock it asserts local_delivery, `out_for_delivery`, assignee (when required), and `Hash::check` on the code, then delegates to the unchanged `ShipmentTransitionService` — so `delivered_at`, history, order → completed, slot-reservation completion and `ShipmentDeliveredEvent` are never duplicated. **An admin needs the code too**; there is no silent bypass. Wrong/absent/stale codes all return the same generic **422** on `code` (never a hint about which). A non-assignee driver gets **404**, not 403.
  * **Resend / recovery:** `POST /admin/shipments/{publicCode}/resend-delivery-code` (`shipment.delivery.resend-code`; local delivery + `out_for_delivery` only, else 422) → `ResendDeliveryVerificationCodeAction`. Because only a hash is stored there is no old plaintext to recover — resend mints a **new** code and thereby retires the old one. Dispatches `DeliveryVerificationCodeIssuedEvent` → `SendDeliveryVerificationCodeSms`, which is **SMS-only**: no second "shipment sent" in-app notification, and no `data` payload to store the code in.
  * **Brute-force protection:** named limiter `delivery-confirm` (`AppServiceProvider`), `config('shipment.delivery.confirmation_max_attempts')` per minute (default 5, `SHIPMENT_DELIVERY_CONFIRM_MAX_ATTEMPTS`), keyed by **caller + shipment public code** so one delivery's exhausted budget cannot strand the rest of a round and one driver's guessing cannot spend another's allowance. Applied to both `mark-delivered` routes on top of `throttle:api`. Exceeding → **429** with `Retry-After`.
  * **Driver API (`/api/v1/delivery/*`) — a separate surface from `/admin/shipments`.** `GET /delivery/shipments` (paginated, optional local-delivery `status` filter), `GET /delivery/shipments/{publicCode}`, `POST /delivery/shipments/{publicCode}/mark-delivered` (body `code` **required**, `receiver_name?`, `note?`). Every lookup starts from `Shipment::where('assigned_delivery_user_id', me)->where('method_type','local_delivery')`, so scoping is structural rather than a check that can be forgotten: another driver's shipment is **404**, indistinguishable from a nonexistent code — a 403 would confirm existence and make the public-code space an oracle. Response uses the narrow `DeliveryAssignmentShipmentDTO`/`DeliveryAssignmentShipmentResource` (shipment code, status, address snapshot incl. map pin, slot, customer name/phone, receiver, failure reason, note, assigned/out-for-delivery/delivered timestamps) — **no** order totals, coupon, payment data, verification hash, or handoff code. The order public code is deliberately absent: the courier's handle is the shipment code, and resolving the order code would cost a cross-module lookup per row.
  * **Address snapshot crosses the wall as a DTO.** `EloquentShipmentManager::findOwnedAddress()` calls `IdentityManagerInterface::getOwnedAddressSnapshot()` instead of querying `addresses`/`provinces`/`cities` directly, and the frozen snapshot now carries `latitude`, `longitude`, `map_address` so a courier navigates to the pin. Ownership failure and non-existence give the same 422 on `address_id`. Historical shipments are never rewritten, and editing an address later never moves an already-placed delivery.
  * **Slot generation:** `DeliverySlotGenerator` + `GenerateDeliverySlotsAction` + `shipment:generate-delivery-slots {--days=}` (scheduled daily 00:30) **and** `POST /admin/shipment/delivery-slots/generate` (`shipment.slot.manage`, optional `days` 1–90, defaults to `config('shipment.delivery.generation_days')`, responds `{data:{created,days}}`) for environments with no cron. Cinema-session slicing (`slot_duration_minutes`, final short slice only if ≥ `minimum_final_slot_minutes`), applies exceptions, idempotent, never overwrites operator-modified or reopens closed slots.
  * **Seeders:** `ShipmentScheduleSeeder` writes the **default** working periods (Sat–Thu, 09:00–13:00 + 16:00–21:00, Friday closed; `firstOrCreate` on `(weekday, starts_at, ends_at)` so admin edits and deactivations survive a re-seed) then generates the first batch of sessions. `ShipmentSampleDataSeeder` runs last in `DatabaseSeeder`: it activates one shipment per paid demo order through `ShipmentManagerInterface::activateForPaidOrder()` and drives it to the state named in the order's `(shipment: …)` note marker, walking the workflow's own transition map breadth-first via `ShipmentTransitionService` — so demo shipments carry real histories, synced order statuses, settled slot reservations, and the notifications the transitions genuinely produce. Covers all 16 states: postal ×5, local delivery ×7, pickup ×4.
  * **Availability:** `DeliverySlotAvailabilityService` — remaining = capacity − admin_reserved − active(held+confirmed). Selectable requires open + not-past + ≥ `minimum_lead_minutes` + ≤ `booking_horizon_days` + not closed-by-exception + remaining>0. Customer slot listing validates `sort` (`date`, `starts_at`, `remaining_capacity`, `capacity`, `created_at`) + `direction` (`asc`, `desc`), defaulting to date/start ascending with deterministic id ties; sorting reuses calculated remaining capacity. Overbooking prevented by `lockForUpdate()` re-check in `holdForPendingOrder`.
  * **Customer method discovery:** omitting `address_id` is valid and returns only canonical config-backed methods with `requires_address:false` (currently `in_person_pickup`). Supplying a valid owned address runs normal address eligibility and keeps pickup; explicitly invalid/foreign ids remain 422. Checkout's address-required validation is unchanged.
  * **Order/Payment integration:** checkout uses `shipment_method_code` (+ `address_id`/`delivery_slot_id`); Order stores immutable `shipment_snapshot` (JSON) + `shipment_method_code`; legacy `shipment_method_id` kept nullable for BC (no longer written). Shipment record is created only when the order becomes **paid** — `EloquentOrderManager::markAsPaid` (the shared paid path, idempotent, row-locked) commits inventory exactly once and calls `activateForPaidOrder`. Pending-order expiry/cancel/replace release the slot hold via `releasePendingOrder` inside the shared `CancelOrderAction::releaseAndCancel`.
  * **Endpoints:** customer `GET /shipment/methods`, `GET /shipment/delivery-slots`, `GET /shipments/{publicCode}`, `GET /orders/{order}/shipment`; delivery worker `GET /delivery/shipments`, `GET /delivery/shipments/{publicCode}`, `POST /delivery/shipments/{publicCode}/mark-delivered`; admin `GET /admin/shipments[/{publicCode}]` + business actions (`start-preparing`, `mark-ready-for-post`, `hand-to-post`, `mark-ready-for-dispatch`, `assign-delivery`, `mark-out-for-delivery`, `mark-delivered`, `resend-delivery-code`, `mark-delivery-failed`, `reschedule`, `mark-ready-for-pickup`, `confirm-pickup`) + slot mgmt (`GET/PATCH /admin/shipment/delivery-slots[/{slot}]`, `.../close`, `.../open`, `POST .../generate`). All under `throttle:api`. No generic "set status" endpoint. The literal `generate` route is declared **before** the `{slot}` routes so it is never read as a slot id.
  * **Permissions:** `shipment.view-own`, `shipment.view-admin`, `shipment.start-preparing`, `shipment.post.{mark-ready,hand-over}`, `shipment.delivery.{mark-ready,dispatch,complete,fail,reschedule}`, `shipment.pickup.{mark-ready,complete}`, `shipment.slot.{view-admin,manage,close,reserve-capacity}`, plus `shipment.delivery.{assign,view-assigned,complete-assigned,resend-code}` (admin gets all; customer gets `view-own`). **The `delivery` role gets exactly two: `shipment.delivery.view-assigned` and `shipment.delivery.complete-assigned`** — never `view-admin`, `dispatch`, `mark-ready`, `complete`, `fail`, `reschedule`, `start-preparing`, `resend-code`, or any slot permission. A courier carries parcels; they do not run fulfillment. `shipment.view-own` is not granted to `delivery` either — it arrives with the `customer` role every courier also holds. Permission-based, 403-before-validation on admin action Form Requests.
  * **Seeders honour the new invariants rather than working around them.** `ShipmentSampleDataSeeder` assigns the demo courier (found via `IdentityManagerInterface::getDeliveryUserIds()`) through the real `AssignShipmentDeliveryAction` before any dispatch, and closes demo deliveries through the real `MarkShipmentDeliveredAction` with the real handoff code — captured from `ShipmentOutForDeliveryEvent` exactly as a customer reads it off their SMS. The capture listener is registered once per run and never removed (removing it would take the real notification listeners with it). `DefaultUsersSeeder` creates a demo courier with `customer + delivery` and phone `09120009001`.
  * **Test suite:** `tests/Feature/Shipment/` (ShipmentMethods, ShipmentSlot, ShipmentPaymentIntegration, ShipmentWorkflow, ShipmentAuthorization, AdminDeliverySlot, AdminShipmentIndex, DeliverySlotSorting, DeliveryWorkingPeriodApi, GenerateDeliverySlotsApi, LocalDeliveryServiceArea, ShipmentNotification, **DeliveryAssignment** (11), **DeliveryWorkerApi** (7), **DeliveryVerificationCode** (16)) + `tests/Unit/Shipment/ShipmentWorkflowTest`. `ShipmentTestCase` gained `createDeliveryUser()`, `assignDriver()`, `captureDeliveryCode()` and `dispatchLocalDelivery()` — tests learn the code the way the customer does, by catching it in flight; a test that dug it out of the database would be testing a leak.

### 📣 9. Notification Module (Status: Active & Complete — business events wired)
* **Responsibility:** Store in-app notifications, expose the customer notification API, and fan a notification out across channels. It owns **no business copy and no event policy** — the caller supplies type/title/message/data and the channel list.
  * **Tables:** `notifications` (`user_id` plain reference — no FK, no join to Identity; `type`, `title`, `message`, JSON `data`, nullable `read_at`, indexes `[user_id, created_at]` + `[user_id, read_at]`), `notification_deliveries` (external-delivery audit: `notification_id` **nullable** — SMS-only notifications have no in-app row — `channel`, `status`, `provider`, `provider_reference`, `sent_at`, `failed_at`, `error`).
  * **Tables (cont.):** `notification_recipient_preferences` (`user_id` plain reference, `notification_type`, `channel`, `enabled`, unique on the triple, lookup index on `[notification_type, channel, enabled]`) — **who wants which notification on which channel**. Deliberately generic; the first and currently only use is `admin_order_paid` + `sms`. Absence of a row means *not selected*, so a fresh deployment texts nobody until an admin picks somebody.
  * **Enums:** `NotificationChannel` (database, sms — no email/push), `DeliveryStatus` (pending, sent, failed, **skipped**), `NotificationTemplate` (internal SMS template constants: payment_success, order_cancelled, **admin_order_paid**, shipment_preparing, **shipment_ready_for_pickup**, **shipment_handed_to_post**, **shipment_out_for_delivery**, shipment_delivered, shipment_assigned_delivery, plus legacy shipment_sent / shipment_sent_delivery_code — `SmsPayloadDTO` takes the enum, never a raw string), `NotificationType` (payment_success, payment_failed, order_cancelled, shipment_preparing, **shipment_ready_for_pickup**, **shipment_handed_to_post**, **shipment_out_for_delivery**, shipment_delivered, shipment_assigned_delivery, admin_order_paid, plus legacy shipment_sent).
  * **Legacy `shipment_sent`:** the type and its two templates stay **defined** but are never emitted. Notification rows written before the split still carry `type: "shipment_sent"` and history is never rewritten, so clients must keep rendering it; the templates stay mapped in `config/sms.php` only so an existing `.env` remains valid.
  * **Admin SMS recipients:** `RecipientPreferenceRepositoryInterface` + `EloquentRecipientPreferenceRepository` (internal to the module — *not* a cross-module contract; nothing outside Notification reads or writes preferences). `SyncAdminSmsRecipientsAction::list()/handle()` returns `AdminSmsRecipientDTO` rows (userId, name, lastName, phone, enabled). Admin-ness is asked of `IdentityManagerInterface::isAdmin()` — **never** `exists:users,id`, which is both a cross-module query and the wrong question. A non-admin id is 422 on `user_ids.{i}` **before** any write; the replacement itself is one transaction and idempotent, and de-selection flips `enabled` to false rather than deleting the row.
  * **SMS is optional, never mandatory.** If the active provider has no template id configured for a template name (or no credentials), the send is **skipped**: no exception, no HTTP call, an info log, an `SmsResultDTO::skipped()`, and a `skipped` delivery row. A recipient with no phone on file is likewise skipped. `failed` is reserved for real attempts that did not succeed (transport error, provider rejection) and for caller misuse (SMS channel requested with no payload), so unconfigured templates never look like delivery incidents.
  * **Public Contract:** `Modules\Notification\Domain\Contracts\NotificationManagerInterface` — `send(NotificationRequestDTO): ?NotificationDTO` (null when no in-app row was requested), `getUserNotifications`, `markAsRead`, `unreadCount`. DTOs: `NotificationRequestDTO` (userId, type, title, message, data, channels, optional `SmsPayloadDTO`), `NotificationDTO`, `SmsPayloadDTO` (template + business parameters).
  * **Channels:** `NotificationChannelInterface` resolved by `NotificationChannelFactory`. `DatabaseChannel` is the only writer of `notifications`; `SmsChannel` converts the request into `SmsMessageDTO`, sends it through `SmsManagerInterface`, and records a delivery row. The database channel runs first so external deliveries can attach to the stored notification.
  * **Failure isolation:** external delivery is best-effort. A failing/misconfigured provider, a missing SMS payload, or a recipient without a phone produce a **failed delivery record**, never an exception into the caller — SMS must never roll back a payment or shipment.
  * **Cross-module rules:** recipient phone is resolved via `IdentityManagerInterface::getUserSummary()` (never the `User` model); SMS goes only through `SmsManagerInterface` (never a provider).
  * **Endpoints:** `GET /api/v1/notifications` (paginated, caller-scoped), `POST /api/v1/notifications/{notification}/read`. Both `auth:sanctum` + `throttle:api`. `NotificationResource` exposes `id/type/title/message/data/read_at/created_at` only — never providers, references, or errors.
  * **Admin endpoints:** `GET` / `PUT /api/v1/admin/notifications/admin-order-paid-sms-recipients` (`auth:sanctum` + `throttle:api`, permission `notification.admin-sms-recipients.manage`). `GET` returns **every** admin with an `enabled` flag so the picker renders from one call; `PUT` takes `{"user_ids": [...]}` (`present|array`, ints ≥ 1, distinct — an empty array legitimately means "nobody") and makes that the entire selected set. `AdminSmsRecipientResource` exposes `user_id/name/last_name/phone/enabled`.
  * **Permissions:** `notification.view-own`, `notification.mark-read-own` (customer + admin); **`notification.admin-sms-recipients.manage`** (admin only — configuring who gets operational SMS is not implied by reading your own notifications). `NotificationPolicy` (typehinted `Authorizable&Authenticatable`) enforces ownership → 403 on another user's notification.
  * **Business integration (live):** business modules publish **integration events** (primitives only) and this module's listeners react. No controller, callback, service, or resource calls `NotificationManagerInterface`.

| Event (owner) | Dispatched from | Listener | Channels |
|---|---|---|---|
| `OrderPaidEvent` (Order) | `EloquentOrderManager::markAsPaid`, after status + inventory commit + shipment activation, on the real transition only | `SendOrderPaidNotifications` | customer db+sms (`payment_success`); **every** admin db (`admin_order_paid`); **only selected** admins additionally sms (`admin_order_paid`, `OrderId`). Selection comes from `notification_recipient_preferences`; each selected admin goes through the ordinary per-user pipeline (one request on `[database, sms]`), so there is no bulk "admin phones" path and a phone-less admin is simply skipped. |
| `PaymentFailedEvent` (Payment) | `HandleZarinpalCallbackAction`, verification-failure branch only | `SendPaymentFailedNotification` | customer db only |
| `OrderCancelledEvent` (Order) | `CancelOrderAction::handle` + `AdminCancelOrderAction::handle` | `SendOrderCancelledNotifications` | customer db+sms (`order_cancelled`) |
| `ShipmentPreparingStartedEvent` | `ShipmentTransitionService` → `preparing` | `SendShipmentPreparingNotification` | customer sms only |
| `ShipmentReadyForPickupEvent` | `ShipmentTransitionService` → `ready_for_pickup` | `SendShipmentReadyForPickupNotifications` | customer db+sms (`shipment_ready_for_pickup`: `OrderId`). The one message a pickup customer needs — nothing arrives at their door to remind them. `picked_up` stays silent. |
| `ShipmentHandedToPostEvent` | `ShipmentTransitionService` → `handed_to_post` | `SendShipmentHandedToPostNotifications` | customer db+sms (`shipment_handed_to_post`: `OrderId` + `TrackingCode`). Postal wording only; never a code-bearing template. |
| `ShipmentOutForDeliveryEvent` | `ShipmentTransitionService` → `out_for_delivery` (first dispatch **and** the `delivery_failed` retry) | `SendShipmentOutForDeliveryNotifications` | customer db + **exactly one** sms (`shipment_out_for_delivery`: `OrderId` + `DeliveryCode`). The raw code fills the template and is then dropped — never in the stored `data`, never the hash either. |
| `ShipmentAssignedToDeliveryEvent` | `AssignShipmentDeliveryAction`, on a **real** change of assignee only | `SendShipmentAssignedToDeliveryNotifications` | **delivery worker** db+sms (`shipment_assigned_delivery`: `ShipmentId`, `OrderId`, `DeliveryDate`, `DeliveryTime`). Never the customer's full address; never the handoff code. |
| `DeliveryVerificationCodeIssuedEvent` | `ResendDeliveryVerificationCodeAction` | `SendDeliveryVerificationCodeSms` | customer **sms only** (`shipment_out_for_delivery` — the same template as the dispatch, because a resend repeats that one message with a fresh code rather than announcing a new event; storing it would mean writing the code down) |
| `ShipmentDeliveredEvent` | `ShipmentTransitionService` → `delivered` | `SendShipmentDeliveredNotifications` | customer db+sms |

  * **Transaction safety + idempotency:** every listener implements `ShouldHandleEventsAfterCommit`, so a rolled-back business transaction notifies nobody. `markAsPaid`'s existing already-paid early return means repeat gateway callbacks never reach the dispatch — no duplicate notifications.
  * **Deliberately silent:** the shared `CancelOrderAction::releaseAndCancel` primitive (checkout uses it to retire a superseded pending order), TTL expiry (`orders:cancel-expired`), and the `picked_up` shipment status. Not implemented anywhere: email, push, marketing.
  * **Enclosure note:** these are *published* integration events in `Domain/Events/`, carrying primitives only — part of a module's public contract alongside Contracts and DTOs. Internal events remain private.
  * **Notification copy** lives in the listener for each flow; `NotificationType` supplies the stable machine keys. There is no template-management system.
  * **Tests:** `tests/Feature/Notification/` (NotificationApiTest 9, NotificationDispatchTest 9, NotificationIntegrationTest 11, **AdminOrderPaidSmsRecipientsTest 14**) + `tests/Feature/Shipment/ShipmentNotificationTest` (**10**) — **53 tests**.

### 📨 10. Sms Module (Status: Infrastructure Ready)
* **Responsibility:** Provider selection, provider abstraction, and provider-specific API formatting. It knows nothing about orders, payments, shipments, or notification rules.
  * **Three outcomes, not two.** `SmsResultDTO` is `success` / `skipped` (nothing attempted — provider not configured for this template; expected, logged at info) / `failure` (a real attempt failed; worth alerting on). Provider template ids stay configuration; template names stay internal constants.
  * **Public Contract:** `Modules\Sms\Domain\Contracts\SmsManagerInterface` — `send(SmsMessageDTO): SmsResultDTO`, `providerName()`. `SmsMessageDTO` is the stable internal format: `receiver` (canonical `09XXXXXXXXX`), `template` (**our** template name, e.g. `payment_success`), `parameters` (**our** business names, e.g. `OrderId`) — identical across providers.
  * **Providers:** `SmsProviderInterface` (internal to the module) implemented by `SmsIrProvider` (maps template name → SMS.ir `templateId`, parameters → `[{name,value}]`, `09…` → `98…`), `LogSmsProvider` (dev default, no network), `FakeSmsProvider` (in-memory singleton for tests). Resolved by `SmsProviderFactory` (mirrors `PaymentGatewayFactory`); unknown name → `UnknownSmsProviderException`, which `SmsManager` degrades into a failed `SmsResultDTO`.
  * **Config:** `config/sms.php` — `sms.default` (`SMS_PROVIDER`) and `sms.providers.smsir.{api_key, endpoint, templates.*}` (`SMS_SMSIR_*_TEMPLATE_ID`). Template ids are provider-specific; template **names** and parameter names are ours and never live in env.
  * **Explicitly not OTP.** Identity's `OtpSenderInterface`/`SmsIrOtpSender` is untouched and unreused — same vendor, different responsibility and contract.
  * **Tests:** `tests/Feature/Sms/SmsManagerTest.php` — **13 tests**, all under `Http::preventStrayRequests()` so no real SMS API can be reached.

---

### Module: Promotion (`Modules/Promotion/`) — Discounts, Coupons, Campaigns ✅

  * **Position in the graph:** a **leaf**. Promotion imports no Catalog/Cart/Order/Payment model, contract, or DTO. `Cart → Catalog → Promotion`, `Order → Catalog`, `Order → Promotion`, `Payment → Order`. Its provider is registered in `bootstrap/providers.php` **before `CatalogServiceProvider`**, because Catalog resolves it for live pricing.
  * **Public Contract:** `Modules\Promotion\Domain\Contracts\PromotionManagerInterface` — `evaluateAutomaticDiscounts(contexts[])` (**batch-only**), `getActiveAutomaticTargetDefinitions()`, `normalizeCouponCode()`, `quoteCoupon()`, `reserveCouponForOrder()`, `redeemCouponForOrder()`, `releaseCouponForOrder()`, `getActiveCampaigns()`, `findActiveCampaignBySlug()`, `getCampaignTargetDefinitions()`, plus admin reads. No Eloquent model crosses the wall.
  * **Tables:** `discounts` (trigger_type `automatic|coupon`, scope `targeted|all`, discount_type `percentage|fixed_amount`, `percentage_bps`, `fixed_amount`, `max_discount_amount`, `min_subtotal`, `starts_at`/`ends_at`, `is_active`, `priority`, soft deletes); `discount_targets` (`target_type` product|variant|category|brand, `target_id` — **loose primitive, no FK into Catalog**, unique per discount+type+id); `coupons` (unique `code`, `usage_limit`, `usage_limit_per_user`, soft deletes); `coupon_redemptions` (`status` reserved|redeemed|released, `discount_amount`, **unique `order_id`**, loose `order_id`/`user_id`); `campaigns` (unique `slug`, `show_on_landing`, `sort_order`, soft deletes); `campaign_discount` pivot.
  * **Two legal discount shapes** (enforced by `DiscountRequestRules` and `SaveDiscountAction`): `automatic` ⇒ `scope=targeted` + ≥1 target + `min_subtotal` rejected; `coupon` ⇒ `scope=all` + **zero** targets. `scope=all` is *not* a store-wide sale — the rule is dormant until a code activates it on an order. Store-wide automatic discounts are unsupported.
  * **Winner algorithm** (`Domain/Services/AutomaticDiscountResolver`): every matching rule is costed in rials; the **largest actual reduction wins**; automatic discounts **never stack**. Specificity does not override savings. Exact ties break by matched target specificity (variant → product → category → brand) → higher `priority` → lowest discount id, so an order's stored snapshot is reproducible.
  * **Arithmetic** (`Domain/Services/DiscountCalculator`): integer **basis points** only (`2000` = 20%, `1250` = 12.5%, `10000` = 100%). `intdiv($amount * $bps, 10000)` — multiplies before dividing, floors consistently, clamps to `[0, amount]`. No float touches a price.
  * **Catalog integration:** `EloquentCatalogManager` injects `PromotionManagerInterface` + the new `Modules\Catalog\Domain\Services\CategoryHierarchy` (scoped binding; loads `id → parent_id` once per request and walks it **both** ways — upwards for pricing context, downwards for `has_discount`/campaign products). Pricing is batched page-wide alongside the existing media/stock maps. `ProductVariantDTO` gains `automaticDiscount` + `effectivePrice()`; `ProductVariantResource` exposes `base_price`, `effective_price`, `discount`.
  * **`compare_at_price` removed:** `product_variants.compare_at_price` is dropped and the field is no longer accepted anywhere in Catalog. `order_items.compare_at_price` is retained as legacy historical data — still rendered, always `null` on new orders, never backfilled.
  * **`has_discount`:** now "≥1 variant has an applicable active automatic discount", compiled to a SQL constraint on Catalog's tables from Promotion's published target ids so pagination counts stay correct. Carries explicit `IS NOT NULL` guards (`NULL IN (…)` is `NULL`, which would otherwise drop uncategorized/brandless products from `has_discount=false`). `min_price`/`max_price`/`sort=cheapest`/`sort=most_expensive` keep **base_price** semantics.
  * **Cart:** lines charge `effective_price`; resources add `effective_price`, `automatic_discount`, `regular_line_total`, `automatic_discount_amount`, `regular_total_price`, `automatic_discount_total`. Cart never calls Promotion and stores **no coupon state**.
  * **Order:** new `order_items.{regular_price_per_unit, automatic_discount_amount_per_unit, automatic_discount_snapshot}` and `orders.{coupon_code, coupon_discount_amount, coupon_snapshot, payment_pricing_finalized_at}`. `OrderManagerInterface::finalizeForPayment()` is the pricing authority: the **first** payment attempt freezes the coupon/no-coupon decision; retrying with the same code or omitting it is allowed, a different code is 422. Coupons are calculated on the post-automatic merchandise subtotal (shipping and tax excluded) and are **never allocated across items**.
  * **Reservation:** row-locked (`lockForUpdate()` on the coupon) *before* counting, with the unique `coupon_redemptions.order_id` as backstop. Limits count `reserved + redeemed`, never `released`. Release lives in `CancelOrderAction::releaseAndCancel()` (customer cancel, admin cancel, TTL expiry, pending-order replacement); redemption lives in `EloquentOrderManager::markAsPaid()` (online capture and in-person cash). Both idempotent. A **failed payment does not release**.
  * **Campaigns:** merchandising groups linking many *different* automatic rules; a campaign never forces its own rule to win. Coupon rules cannot be linked. Storefront endpoints live in **Catalog** (`GET /catalog/campaigns`, `/{slug}`, `/{slug}/products`) — Promotion publishes metadata + raw target ids only.
  * **API:** admin `/api/v1/admin/promotions/{discounts,coupons,campaigns,redemptions}`; customer `POST /api/v1/orders/{order}/coupon/check` (advisory, reserves nothing) and optional `coupon_code` on `POST /api/v1/payments/initialize`. Promotion itself exposes **no** customer-facing routes.
  * **Permissions:** `promotion.view-admin`, `promotion.create`, `promotion.update`, `promotion.delete`, `promotion.coupon.manage`, `promotion.campaign.manage` — seeded to `admin`, none to `customer`. Customer-facing promotion surfaces require no promotion permission.
  * **Tests:** `tests/Unit/Promotion/` (24) + `tests/Feature/Promotion/` (96) — **120 tests**, covering integer math, winner selection and every tie-break, targeting and ancestry, `has_discount` pagination, base-price filter/sort preservation, cart/order pricing, the full coupon lifecycle, campaigns, and the six-permission matrix.
  * **Explainer:** `DISCOUNT_ARCHITECTURE.html`.

---

### Module: Analytics (`Modules/Analytics/`) — Reporting & Read Model Authority ✅

  * **Position in the graph:** a **leaf / consumer**. Analytics imports NO models from any other module and executes NO database queries against other modules' tables. It is strictly a read-model and reporting system driven by published integration domain events.
  * **Integration Events Consumed & Event Idempotency:**
    - Every domain event consumed carries an immutable string `$eventId` UUID.
    - All listener executions check `analytics_processed_events` within the same `DB::transaction()`: duplicate event deliveries (queue retries, worker restarts) are detected and silently skipped.
    - Consumes:
      - `OrderPaidEvent` (Order) → updates `analytics_daily_sales`, `analytics_product_sales`, `analytics_variant_sales`, `analytics_category_sales` (with hierarchical propagation to all ancestor categories), `analytics_customer_stats`, `analytics_discount_usage`, `analytics_coupon_usage`, and records product-category bindings in `analytics_product_categories`.
      - `OrderCancelledEvent` (Order) → updates `analytics_daily_sales` (`cancelled_orders_count`, `refund_amount`, net revenue adjustment).
      - `PaymentSuccessfulEvent`, `PaymentFailedEvent`, `PaymentCancelledEvent` (Payment) → updates `analytics_payment_stats` (`successful_count`, `failed_count`, `cancelled_count`, `total_amount`). Never alters sales revenue (zero double-counting).
      - `ShipmentAssignedToDeliveryEvent`, `ShipmentHandedToPostEvent`, `ShipmentDeliveredEvent`, `ShipmentDeliveryFailedEvent` (Shipment) → updates `analytics_delivery_stats` and `analytics_driver_stats` with delivery duration and success/failure metrics.
  * **Public Contract:** `Modules\Analytics\Domain\Contracts\AnalyticsManagerInterface` — `getDashboard()`, `getSales(filters)`, `getProducts(filters)`, `getCustomers(filters, perPage)`, `getDelivery(filters)`.
  * **Tables:** 10 aggregate reporting tables + 1 index table + 1 processed events table (store raw IDs only, zero cross-module foreign keys):
    - `analytics_daily_sales` (date UNIQUE, orders_count, paid_orders_count, cancelled_orders_count, gross_revenue, discount_amount, coupon_amount, refund_amount, net_revenue)
    - `analytics_product_sales` ((date, product_id) UNIQUE, quantity_sold, orders_count, gross_revenue, discount_amount, net_revenue)
    - `analytics_variant_sales` ((date, variant_id) UNIQUE, quantity_sold, orders_count, gross_revenue, discount_amount, net_revenue)
    - `analytics_category_sales` ((date, category_id) UNIQUE, quantity_sold, orders_count, gross_revenue, discount_amount, net_revenue) — **Hierarchical propagation:** when a product is purchased, its immediate category AND all parent/ancestor categories in the hierarchy are updated.
    - `analytics_customer_stats` (customer_id UNIQUE, orders_count, total_spent, total_discount_received, average_order_value, first_order_at, last_order_at)
    - `analytics_payment_stats` ((date, gateway) UNIQUE, successful_count, failed_count, cancelled_count, total_amount)
    - `analytics_delivery_stats` ((date, method) UNIQUE, assigned_count, delivered_count, failed_count, total_delivery_minutes) — **Exact weighted averages:** stores additive `total_delivery_minutes` to calculate accurate weighted averages dynamically.
    - `analytics_driver_stats` ((date, driver_id) UNIQUE, assigned_count, completed_count, failed_count, total_delivery_minutes)
    - `analytics_discount_usage` ((date, discount_id) UNIQUE, usage_count, discount_amount, generated_revenue)
    - `analytics_coupon_usage` ((date, coupon_id) UNIQUE, usage_count, discount_amount, generated_revenue)
    - `analytics_product_categories` ((product_id, category_id) UNIQUE) — event-populated index allowing self-contained category product filtering without querying Catalog tables.
    - `analytics_processed_events` (event_id UNIQUE, event_name, processed_at) — atomic idempotency deduplication ledger.
  * **Admin API:** `/api/v1/admin/analytics/{dashboard, sales, products, customers, delivery}` guarded by Sanctum and `analytics.view` permission.
  * **Permissions:** `analytics.view` — seeded to `admin`, none to `customer`.
  * **Tests:** `tests/Feature/Analytics/` (41 tests across 9 classes: `OrderPaidAnalyticsTest`, `OrderCancelledAnalyticsTest`, `PaymentAnalyticsTest`, `ShipmentAnalyticsTest`, `AnalyticsAuthorizationTest`, `AdminAnalyticsApiTest`, `AnalyticsEventIdempotencyTest`, `DeliveryAnalyticsAccuracyTest`, `FinancialAnalyticsSafetyTest`).

---

### Module: Review (`Modules/Review/`) — Product Ratings & Comments ✅

  * **Position in the graph:** a **leaf consumer**. Review imports no Catalog model ever; it reaches Order only through
    `OrderManagerInterface::hasPurchasedProduct()` and pushes aggregates into Catalog only through
    `CatalogManagerInterface::syncRatingSummary()`. One entity — `Review` — is both star rating and comment; there is no
    separate Comment entity and no blog support yet.
  * **Subject reference pattern:** `subject_type` + `subject_id` are plain loose columns (no FK, no Eloquent morph map).
    `subject_type` is an enum-backed whitelist (`ReviewSubjectType`, today only `product`); adding `blog_post` later means
    adding an enum case, never a schema change or scattered string literals. Consuming modules resolve their own subjects —
    Catalog calls the batch `getSummaryForSubjects()` beside its other page-wide batching; Review's public read endpoint
    returns reviews only and never fetches product data.
  * **Table:** `reviews` (`uuid` = unique public code `bdr-XXXXXX`, nullable at the DB level per the public-code rule;
    `subject_type`/`subject_id`; loose `user_id`; nullable tinyint `rating`; text `body`; JSON `gallery_media_ids`
    (pre-uploaded Media ids — no inline uploads); server-computed `verified_purchase`; `status` pending/approved/rejected,
    default pending; `seller_reply`/`seller_reply_at`; timestamps). **Unique index on `(user_id, subject_type,
    subject_id)`** — one review per user per subject.
  * **Verified-purchase gating is validation, not an Action correction.** Purchase status is resolved server-side through
    `hasPurchasedProduct()` (realized statuses only — the same set `sales_count` uses) before rules run. Purchaser ⇒
    `rating` required integer 1–5, photos optional (each id checked against MediaManagerInterface), body required.
    Non-purchaser ⇒ `rating`/`gallery_media_ids` must be absent/null — sending them is a **422**, never silently dropped;
    body-only comments are always allowed. `verified_purchase` is re-resolved on every edit, so a commenter who later buys
    the product upgrades their existing review via PATCH (rating/photos accepted, flag flips true).
  * **Edit resets moderation.** Create and the create-or-upgrade path both stamp `status=pending`; editing an approved
    review sends it back to pending for re-moderation. POST returns **201 on a fresh create, 200 on the upgrade-in-place**
    (`ReviewWriteResultDTO.created`). PATCH `/reviews/{uuid}` is owner-only via `ReviewPolicy` (standard **403** for
    non-owners — deliberately unlike the driver-API 404 scoping).
  * **Rating aggregation mirrors `sales_count` exactly:** hourly `reviews:sync-product-ratings` aggregates approved +
    non-null-rating rows per product from Review's own tables and pushes absolute tallies through
    `syncRatingSummary()`. An approved comment without a rating never moves the average (so "4.3★ (8 ratings) · 12
    reviews" is correct). Products that once had counters but currently have no approved rated rows are synced back to
    zero — every product ever pushed has at least one review row (rows are never deleted), so the sweep is self-correcting
    without a global reset. Catalog stores raw `products.rating_sum`/`products.rating_count` (integer, indexed semantics
    identical to `sales_count`) and derives `rating_average` at read time.
  * **Catalog additions:** `sort=rating` (pure integer ordering `(rating_sum*10000)/rating_count`, unrated last) and
    `min_rating` (integer math `rating_sum >= n × rating_count`, unrated excluded) on all three product listings;
    product reads expose derived `rating_average` + `rating_count`.
  * **Endpoints:** customer `POST /api/v1/reviews` (`review.create`, throttle `api`), `PATCH /api/v1/reviews/{uuid}`
    (owner), `GET /api/v1/reviews?subject_type=&subject_id=&sort=newest|highest|lowest` (public, approved-only regardless
    of any `status` param passed, throttled `public`); admin `GET /api/v1/admin/reviews?status=&subject_type=`
    (`review.view-admin`), `PATCH /api/v1/admin/reviews/{uuid}/status` and `POST /api/v1/admin/reviews/{uuid}/reply`
    (`review.moderate`). Moderation allows pending→approved/rejected and approved↔rejected re-review; nothing transitions
    into `pending` (system-only, on create/edit). Reply is single and overwritable.
  * **Permissions:** `review.create` (customer + admin); `review.view-admin`, `review.moderate` (admin only) — seeded by
    `ReviewPermissionsSeeder`.
  * **Tests:** `tests/Feature/Review/` — **35 tests** (ReviewTest 13, ReviewModerationTest 9, ReviewAuthorizationTest 7,
    ReviewRatingSyncTest 6): purchase gating matrix, upsert/upgrade semantics, edit-resets-pending, public approved-only
    guarantee, moderation transitions incl. the pending prohibition, reply overwrite, rating-summary correctness, and
    Catalog sort/filter integration.

---



| Module | Status | Tests |
|---|---|---|
| Identity | ✅ Complete (OTP + password) | AddressTest, ProfileTest, AuthControllerTest (10), PasswordAuthTest (11), RolePermissionTest |
| Media | ✅ Complete | 12 passing (MediaUploadTest) + existing MediaManagerTest |
| Catalog | ✅ Complete | 128 passing across 4 test classes |
| Inventory | ✅ Complete | 24 passing across 2 test classes |
| Cart | ✅ Complete | 22 passing (CartTest) |
| Order | ✅ Complete | 14 OrderTest + 11 AdminOrderTest passing |
| Payment | ✅ Complete | 12 passing (PaymentTest) |
| Shipment | ✅ Complete | 55 passing across 7 test classes |
| Notification | ✅ Complete (events wired) | 53 passing across 5 test classes |
| Sms | ✅ Infrastructure ready | 13 passing (SmsManagerTest) |
| Promotion | ✅ Complete | 120 passing (24 unit + 96 feature across 6 classes) |
| Analytics | ✅ Complete & Hardened | 41 passing across 9 feature test classes |
| Review | ✅ Complete | 35 passing across 4 feature test classes |

**Full suite: 808 tests, 806 passing.** Two unrelated pre-existing failures remain — `ProfileTest::authenticated_user_can_update_profile` and `Analytics\FinancialAnalyticsSafetyTest::payment_failed_and_cancelled_events_do_not_mutate_payment_stats` — both verified to fail identically on a clean tree and out of scope.
