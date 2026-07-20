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
  * **Public Cross-Module Contract:**
      * `Modules\Identity\Domain\Contracts\IdentityManagerInterface`: exposes `isAdmin(int $userId): bool`. Available for cross-module role checks, but **prefer direct permission checks via `$user->can('...')` in policies instead** — see Authorization Pattern below.
      * `getUserSummary(int $userId): UserSummaryDTO` — returns `{id, name, lastName, phone, email}` (`Domain/DTOs/UserSummaryDTO.php`). Consumed by Order's `CreateOrderAction` to build the immutable `customer_snapshot` at checkout; never leaks the `User` model across the boundary.
      * Concrete: `EloquentIdentityManager` (bound in `IdentityServiceProvider::register()`). Internally calls `User::find()->hasRole('admin')` / `User::findOrFail()->…` — all Spatie internals stay inside Identity.
  * **Route structure:** user-facing address routes registered under `prefix('addresses')` (plural). Admin user management under `prefix('admin/users')`. Profile self-service under `prefix('profile')`.
  * **Known fix applied:** `UpdateAddressRequest` had `city_id` as `required` instead of `sometimes` — corrected so PATCH requests can update partial fields without supplying city.
  * **Address map pin:** `addresses` carries `latitude`/`longitude` (`decimal(10,7)`) and a nullable `map_address` text line (the map's reverse-geocoded string, distinct from the user-typed `address`). Columns are nullable at the DB level, but `StoreAddressRequest` requires `latitude`/`longitude` (`numeric`, `between:-90,90` / `between:-180,180`) on create; `UpdateAddressRequest` treats all three as `sometimes`. `map_address` is always optional. Exposed on `AddressResource`.
  * **Profile:** `User` carries a nullable `last_name` (migration `2026_07_07_000001_add_last_name_to_users_table`) alongside `name`, both mass-assignable. `last_name` is settable at OTP registration and via profile update (`PATCH /api/v1/profile` / admin `PATCH /api/v1/admin/users/{user}`), and is exposed on `UserResource` and `AuthUserResource`.
  * **Test suite:** `AddressTest` (21 — includes map-pin required/range/nullable/update coverage), `ProfileTest` (9 — includes `last_name` view + update), `AuthControllerTest` (10 — full OTP request/verify matrix: create-on-request incl. `last_name`, no-duplicate, invalid phone, verify+token, wrong/expired/unknown code, single-use replay, me, logout), `PasswordAuthTest` (15 — check-user new/existing/invalid, OTP registration leaves password null + verify ignores password, authenticated set-password hash storage/login/short/missing/guest-401, password login success/wrong-password/unknown-phone/password-less/missing-password), `RolePermissionTest` — all passing.

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
      * `product_variants`: Houses concrete purchasable inventory details tracking unique `sku`, currency integers (`base_price`, `compare_at_price`), attributes JSON arrays, a **per-variant image** (`media_id`), and a **`is_default` boolean** marking exactly one variant per product as the storefront fallback. The single-true invariant is enforced at the application layer.
  * **Domain Models (Step 3):**
      * `Category`, `Product`, `ProductImage`, `ProductVariant` models declared with clean internal relationships. `ProductVariant` casts `is_default` → boolean, `base_price`/`compare_at_price` → integer (Cents Rule), `attributes` → array.
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
      * `order_items`: `id`, `order_id` (FK → orders, cascade delete), `sku`, `product_title`, `variant_attributes` (JSON), `product_snapshot` (nullable JSON — `{title, sku, image_url, attributes}`, captured from the enriched `CartItemDTO` at checkout, **not** a second Catalog call; later catalog edits never touch it), `quantity` (int), `max_quantity_per_order_snapshot` (nullable int), `price_per_unit` (int), `line_total` (int), timestamps. All monetary columns are integers (Cents Rule). Prices and snapshots are captured at order creation — they never update even if the catalog or the customer's profile changes.
  * **Domain Models (internal):** `Order`, `OrderItem`.
  * **Public Contract:** `Modules\Order\Domain\Contracts\OrderManagerInterface`:
      * `createOrderFromCart(int $userId, int $addressId, int $shipmentMethodId, ?string $notes): OrderDTO` — full checkout orchestration.
      * `markAsPaid(int $orderId, string $transactionRef): OrderDTO` — transitions to `paid`, stores transaction reference.
      * `markAsComplete(int $orderId): OrderDTO` — transitions to `processing`.
      * `getUserOrders(int $userId, int $perPage = 15): LengthAwarePaginator` — paginator items are DTOs (mapped via `->through()`).
      * `findOrder(int $orderId): ?OrderDTO`.
  * **DTOs:** `OrderDTO` (id, userId, status as `OrderStatus` enum, totalAmount, shippingCost, taxAmount, shipmentMethodId, shippingAddress array, shipmentSnapshot, **customerSnapshot**, transactionRef, notes, createdAt, items[]), `OrderItemDTO` (id, orderId, sku, productTitle, variantAttributes, **productSnapshot**, quantity, maxQuantityPerOrderSnapshot, pricePerUnit, lineTotal). Both snapshot fields are nullable arrays, exposed as-is on `OrderResource`/`OrderItemResource`.
  * **Enum:** `OrderStatus: string` — PENDING, PAID, PROCESSING, SHIPPED, CANCELLED, FAILED.
  * **Exceptions:** `EmptyCartException`, `InvalidAddressException` (both in `Domain/Exceptions/`).
  * **`CreateOrderAction`** — constructor deps: `CartManagerInterface`, `CatalogManagerInterface`, `InventoryManagerInterface`, `CancelOrderAction`, `ShipmentManagerInterface`, `IdentityManagerInterface`. Single `DB::transaction()`:
      1. Fetch enriched cart via `CartManagerInterface::getCart()`; validate per-SKU quantity limits against `CatalogManagerInterface::getVariantsBySkus()`.
      2. Resolve the shipment selection snapshot (`ShipmentSelectionDTO::toSnapshot()`) and the customer snapshot (`IdentityManagerInterface::getUserSummary($userId)`, mapped to `{name, last_name, phone, email}`) **before** the transaction opens — both are pure reads.
      3. Cancel any existing pending order for the user + `releaseAndCancel`.
      4. Create `Order` with `shipment_snapshot` + `customer_snapshot`.
      5. Create each `OrderItem` (prices snapshotted from `CartItemDTO`, plus `product_snapshot` built from `CartItemDTO::{sku, productName, imageUrl, attributes}` — **no second Catalog call**), then `reserveStock(sku, qty, orderId)`.
      6. `holdForPendingOrder()` on the Shipment contract (local-delivery slot; no-op otherwise).
  * **`CancelOrderAction`** — dep: `InventoryManagerInterface`. Owns the single "release reservations + mark cancelled" primitive (`releaseAndCancel(Order)`, caller-transactional) reused by `CreateOrderAction` (pending replacement) and `CancelExpiredOrdersAction`. `handle(orderId, userId)` is the user-facing cancel: 404 if missing, **403 if the order is not owned by `userId`**, 422 unless status is `pending`; otherwise releases every item's reservation and sets `cancelled` inside a transaction.
  * **`CancelExpiredOrdersAction`** — dep: `CancelOrderAction`. Finds pending orders with `created_at < now() - 15 min` and calls `releaseAndCancel` per order (each wrapped in its own transaction). Run every minute by `orders:cancel-expired` Artisan command scheduled in `routes/console.php`.
  * **`AdminCancelOrderAction`** — dep: `CancelOrderAction`. Admin/operator cancel: no ownership check, but reuses `CancelOrderAction::releaseAndCancel` (no duplicated release/cancel logic). Restricted to `pending` orders only — 404 if missing, 422 if not pending. Paid/shipped cancellation (refund + committed-stock return) is a deliberate future flow, not exposed here.
  * **HTTP Endpoints (customer):**
      * `POST /api/v1/orders` — body `{address_id, shipment_method_id, notes?}`; requires `auth:sanctum` + `order.create`; returns 201 OrderResource. 422 on empty cart or invalid address.
      * `GET /api/v1/orders` — paginated order history for the authenticated user; requires `auth:sanctum`; returns paginated OrderResource collection.
      * `POST /api/v1/orders/{order}/cancel` — user cancels **their own** pending order; releases reserved stock and returns 200 OrderResource. `auth:sanctum`; 403 for another user's order, 404 if missing, 422 if not `pending`.
  * **HTTP Endpoints (admin/operator — `AdminOrderController`, view/search/cancel only, no status/create/edit):**
      * `GET /api/v1/admin/orders` — paginated `{data, meta, links}`. `viewAny` policy → `order.view-admin`. Filters (via `IndexAdminOrdersRequest`): `status`, `order_id`, `user_id`, `date_from`, `date_to`, `per_page` (1–100). Rows (`AdminOrderListResource`): id, status, total_amount, created_at, customer summary (from `customer_snapshot` — never re-queries Identity), `item_count`.
      * `GET /api/v1/admin/orders/{order}` — full detail (`AdminOrderResource`). `view` policy → `order.view-admin`. Order fields + customer (from `customer_snapshot`) + items (from `product_snapshot`) + `shipping_address`/`shipment_snapshot` + live `shipment` status **resolved only via `ShipmentManagerInterface::findForOrder`** (null until paid). 404 via route-model binding.
      * `POST /api/v1/admin/orders/{order}/cancel` — admin cancel via `AdminCancelOrderAction`. `cancel` policy → `order.cancel-admin`. Returns 200 detail; 422 if not pending. Order status transitions otherwise belong to Shipment — there is intentionally no status-mutation / create / edit endpoint.
  * **Authorization:** `StoreOrderRequest::authorize()` checks `order.create` → 403 before validation (customer). Customer cancellation is ownership-gated in `CancelOrderAction` (self-service, like Cart). **Admin reads/cancel go through `OrderPolicy`** (`viewAny`/`view` → `order.view-admin`, `cancel` → `order.cancel-admin`), typehinted against `Authorizable`, registered via `Gate::policy(Order::class, OrderPolicy::class)` in `OrderServiceProvider::boot()`.
  * **Permissions:** `order.create`, `order.view-own`, `order.view-admin`, `order.cancel-admin` — admin receives all four; customer receives `order.create` + `order.view-own`.
  * **Test suite:** `OrderTest` — **14 tests**: price snapshot + stock reservation, immutable customer/product snapshot creation + post-update immutability (profile edit / catalog title edit do not alter a placed order), auto-cancel pending, TTL expiry command, user cancel releases stock, cancel ownership 403 / 404 / non-pending 422, 401/422 auth matrix. `AdminOrderTest` — **11 tests**: admin list (+status filter), admin detail (customer/product snapshots + null shipment), 404, customer-forbidden list/detail/cancel (403), admin cancel releases inventory, non-pending 422, no status-mutation endpoints exist (404/405), 401 matrix.

### 💳 7. Payment Module (Status: Active & Complete)
* **Responsibility:** Hybrid payment processing — cash/offline (`in_person`) and online gateway (`online`) via the Strategy Pattern.
  * **Tables:** `payments`: id, order_id (indexed bigint, no cascade FK), method_type (string), gateway (string nullable), transaction_reference (string unique nullable), amount (int — Cents Rule), status (string), gateway_response (json nullable), timestamps.
  * **Domain Enums:** `PaymentMethodType` (ONLINE, IN_PERSON), `PaymentStatus` (INITIATED, CAPTURED, FAILED, REFUNDED, PENDING_CASH).
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
  * **Tables:** `shipments` (operational record, `public_code` unique customer/admin handle, unique `order_id` → idempotent activation, loose `user_id`/media refs, JSON snapshots, per-status timestamps), `shipment_status_histories` (append-only, `created_at` only), `delivery_working_periods` (recurring weekly templates), `delivery_slots` (generated dated sessions, unique `[delivery_date, starts_at, ends_at]`, `capacity` + `admin_reserved_capacity`, **no `reserved_count` column**), `delivery_slot_reservations` (source of truth for consumed capacity), `delivery_schedule_exceptions` (`closed` / `custom_hours`). `delivery_date`/times stored as plain strings (`DeliverySlot::dateString()`) — the `date` cast stores `Y-m-d 00:00:00` on SQLite and breaks equality/dedup.
  * **Enums:** `ShipmentMethodType` (postal/local_delivery/pickup), `ShipmentStatus` (pending, preparing, ready_for_post, handed_to_post, ready_for_dispatch, out_for_delivery, delivered, delivery_failed, ready_for_pickup, picked_up, cancelled — with `label()` + `toOrderStatus()`), `DeliverySlotStatus` (open/closed/cancelled), `ReservationStatus` (held/confirmed/released/expired/cancelled/completed; `activeStatuses()` = held+confirmed consume capacity).
  * **Public Contract:** `Modules\Shipment\Domain\Contracts\ShipmentManagerInterface` — `getAvailableMethods`, `getAvailableDeliverySlots`, `validateSelection` (→ `ShipmentSelectionDTO`, throws `ValidationException`), `holdForPendingOrder` (locks slot row, held reservation, local only, returns `?DeliverySlotReservationDTO`), `releasePendingOrder` (idempotent), `activateForPaidOrder` (idempotent by `order_id`, `?ShipmentDTO` — null when no selection), `findForOrder`. Plus `LocalDeliveryEligibilityInterface` (default permissive `ConfigLocalDeliveryEligibility` — swap the binding to enforce a supported-region rule).
  * **Workflows (method-specific transition maps):** `PostalShipmentWorkflow` (pending→preparing→ready_for_post→handed_to_post, terminal — **never** in_transit/out_for_delivery/delivered), `LocalDeliveryShipmentWorkflow` (…→ready_for_dispatch→out_for_delivery→delivered | delivery_failed→reschedule/retry/cancel), `PickupShipmentWorkflow` (…→ready_for_pickup→picked_up). Resolved by `ShipmentWorkflowResolver`. `ShipmentTransitionService` is the single atomic primitive: lock → assert transition → mutate + timestamp → history → `OrderManagerInterface::syncStatusFromShipment` → reservation complete/release. Invalid transitions throw `InvalidShipmentTransitionException` (renders 422).
  * **Slot generation:** `DeliverySlotGenerator` + `GenerateDeliverySlotsAction` + `shipment:generate-delivery-slots {--days=}` (scheduled daily 00:30). Cinema-session slicing (`slot_duration_minutes`, final short slice only if ≥ `minimum_final_slot_minutes`), applies exceptions, idempotent, never overwrites operator-modified or reopens closed slots.
  * **Availability:** `DeliverySlotAvailabilityService` — remaining = capacity − admin_reserved − active(held+confirmed). Selectable requires open + not-past + ≥ `minimum_lead_minutes` + ≤ `booking_horizon_days` + not closed-by-exception + remaining>0. Overbooking prevented by `lockForUpdate()` re-check in `holdForPendingOrder`.
  * **Order/Payment integration:** checkout uses `shipment_method_code` (+ `address_id`/`delivery_slot_id`); Order stores immutable `shipment_snapshot` (JSON) + `shipment_method_code`; legacy `shipment_method_id` kept nullable for BC (no longer written). Shipment record is created only when the order becomes **paid** — `EloquentOrderManager::markAsPaid` (the shared paid path, idempotent, row-locked) commits inventory exactly once and calls `activateForPaidOrder`. Pending-order expiry/cancel/replace release the slot hold via `releasePendingOrder` inside the shared `CancelOrderAction::releaseAndCancel`.
  * **Endpoints:** customer `GET /shipment/methods`, `GET /shipment/delivery-slots`, `GET /shipments/{publicCode}`, `GET /orders/{order}/shipment`; admin `GET /admin/shipments[/{publicCode}]` + business actions (`start-preparing`, `mark-ready-for-post`, `hand-to-post`, `mark-ready-for-dispatch`, `mark-out-for-delivery`, `mark-delivered`, `mark-delivery-failed`, `reschedule`, `mark-ready-for-pickup`, `confirm-pickup`) + slot mgmt (`GET/PATCH /admin/shipment/delivery-slots[/{slot}]`, `.../close`, `.../open`). All under `throttle:api`. No generic "set status" endpoint.
  * **Permissions:** `shipment.view-own`, `shipment.view-admin`, `shipment.start-preparing`, `shipment.post.{mark-ready,hand-over}`, `shipment.delivery.{mark-ready,dispatch,complete,fail,reschedule}`, `shipment.pickup.{mark-ready,complete}`, `shipment.slot.{view-admin,manage,close,reserve-capacity}` (admin gets all; customer gets `view-own`). Permission-based, 403-before-validation on admin action Form Requests.
  * **Test suite:** `tests/Feature/Shipment/` (ShipmentMethods, ShipmentSlot, ShipmentPaymentIntegration, ShipmentWorkflow, ShipmentAuthorization, AdminDeliverySlot, AdminShipmentIndex) + `tests/Unit/Shipment/ShipmentWorkflowTest` — **55 tests, 225 assertions**.

### 📣 9. Notification Module (Status: Infrastructure Ready — events not wired)
* **Responsibility:** Store in-app notifications, expose the customer notification API, and fan a notification out across channels. It owns **no business copy and no event policy** — the caller supplies type/title/message/data and the channel list.
  * **Tables:** `notifications` (`user_id` plain reference — no FK, no join to Identity; `type`, `title`, `message`, JSON `data`, nullable `read_at`, indexes `[user_id, created_at]` + `[user_id, read_at]`), `notification_deliveries` (external-delivery audit: `notification_id` **nullable** — SMS-only notifications have no in-app row — `channel`, `status`, `provider`, `provider_reference`, `sent_at`, `failed_at`, `error`).
  * **Enums:** `NotificationChannel` (database, sms — no email/push), `DeliveryStatus` (pending, sent, failed, **skipped**), `NotificationTemplate` (internal SMS template constants: payment_success, order_cancelled, shipment_preparing, shipment_sent, shipment_delivered — `SmsPayloadDTO` takes the enum, never a raw string).
  * **SMS is optional, never mandatory.** If the active provider has no template id configured for a template name (or no credentials), the send is **skipped**: no exception, no HTTP call, an info log, an `SmsResultDTO::skipped()`, and a `skipped` delivery row. A recipient with no phone on file is likewise skipped. `failed` is reserved for real attempts that did not succeed (transport error, provider rejection) and for caller misuse (SMS channel requested with no payload), so unconfigured templates never look like delivery incidents.
  * **Public Contract:** `Modules\Notification\Domain\Contracts\NotificationManagerInterface` — `send(NotificationRequestDTO): ?NotificationDTO` (null when no in-app row was requested), `getUserNotifications`, `markAsRead`, `unreadCount`. DTOs: `NotificationRequestDTO` (userId, type, title, message, data, channels, optional `SmsPayloadDTO`), `NotificationDTO`, `SmsPayloadDTO` (template + business parameters).
  * **Channels:** `NotificationChannelInterface` resolved by `NotificationChannelFactory`. `DatabaseChannel` is the only writer of `notifications`; `SmsChannel` converts the request into `SmsMessageDTO`, sends it through `SmsManagerInterface`, and records a delivery row. The database channel runs first so external deliveries can attach to the stored notification.
  * **Failure isolation:** external delivery is best-effort. A failing/misconfigured provider, a missing SMS payload, or a recipient without a phone produce a **failed delivery record**, never an exception into the caller — SMS must never roll back a payment or shipment.
  * **Cross-module rules:** recipient phone is resolved via `IdentityManagerInterface::getUserSummary()` (never the `User` model); SMS goes only through `SmsManagerInterface` (never a provider).
  * **Endpoints:** `GET /api/v1/notifications` (paginated, caller-scoped), `POST /api/v1/notifications/{notification}/read`. Both `auth:sanctum` + `throttle:api`. `NotificationResource` exposes `id/type/title/message/data/read_at/created_at` only — never providers, references, or errors.
  * **Permissions:** `notification.view-own`, `notification.mark-read-own` (customer + admin). `NotificationPolicy` (typehinted `Authorizable&Authenticatable`) enforces ownership → 403 on another user's notification.
  * **Deliberately absent (future phase):** no domain events, listeners, or `EventServiceProvider` changes; nothing in Order/Payment/Shipment calls this module yet. Planned integration points — payment success (SMS + in-app), payment failed (in-app), order cancelled (SMS + in-app), shipment preparing (SMS), shipment sent (SMS + in-app), shipment delivered (SMS + in-app), admin paid-order-created (in-app).
  * **Tests:** `tests/Feature/Notification/` (NotificationApiTest 9, NotificationDispatchTest 9) — **18 tests**.

### 📨 10. Sms Module (Status: Infrastructure Ready)
* **Responsibility:** Provider selection, provider abstraction, and provider-specific API formatting. It knows nothing about orders, payments, shipments, or notification rules.
  * **Three outcomes, not two.** `SmsResultDTO` is `success` / `skipped` (nothing attempted — provider not configured for this template; expected, logged at info) / `failure` (a real attempt failed; worth alerting on). Provider template ids stay configuration; template names stay internal constants.
  * **Public Contract:** `Modules\Sms\Domain\Contracts\SmsManagerInterface` — `send(SmsMessageDTO): SmsResultDTO`, `providerName()`. `SmsMessageDTO` is the stable internal format: `receiver` (canonical `09XXXXXXXXX`), `template` (**our** template name, e.g. `payment_success`), `parameters` (**our** business names, e.g. `OrderId`) — identical across providers.
  * **Providers:** `SmsProviderInterface` (internal to the module) implemented by `SmsIrProvider` (maps template name → SMS.ir `templateId`, parameters → `[{name,value}]`, `09…` → `98…`), `LogSmsProvider` (dev default, no network), `FakeSmsProvider` (in-memory singleton for tests). Resolved by `SmsProviderFactory` (mirrors `PaymentGatewayFactory`); unknown name → `UnknownSmsProviderException`, which `SmsManager` degrades into a failed `SmsResultDTO`.
  * **Config:** `config/sms.php` — `sms.default` (`SMS_PROVIDER`) and `sms.providers.smsir.{api_key, endpoint, templates.*}` (`SMS_SMSIR_*_TEMPLATE_ID`). Template ids are provider-specific; template **names** and parameter names are ours and never live in env.
  * **Explicitly not OTP.** Identity's `OtpSenderInterface`/`SmsIrOtpSender` is untouched and unreused — same vendor, different responsibility and contract.
  * **Tests:** `tests/Feature/Sms/SmsManagerTest.php` — **13 tests**, all under `Http::preventStrayRequests()` so no real SMS API can be reached.

---

## 8. Completed & Ready

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
| Notification | ✅ Infrastructure ready (events pending) | 18 passing across 2 test classes |
| Sms | ✅ Infrastructure ready | 13 passing (SmsManagerTest) |

**Shipment adds 55 tests, 225 assertions — all green.** (5 unrelated, pre-existing ProfileTest/ProductsTest failures are out of scope.)

