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
* **Responsibility:** Passwordless OTP authentication, user profile management, shipping location matrices, and user addresses.
  * **Key Entities:** `User`, `Address`, `Province`, `City`.
  * **State:** Fully functional, using decoupled Eloquent repositories bound to service contracts (`UserRepositoryInterface`, `AddressRepositoryInterface`).
  * **Authentication — Passwordless OTP (phone-based, unified register+login):**
      * Schema (`2026_06_15_120000_refactor_users_table_for_otp_auth`): `password` and `name` made nullable; added `otp_code` (stored **hashed**, hidden), `otp_expires_at` (datetime cast), and a loose `media_id` (FK-free profile image, per Media coupling rule).
      * `POST /api/v1/otp/request` — body `phone` (required, `09xxxxxxxxx`), optional `name`. Finds the user by phone or **creates one on first contact** (assigns `customer` role; sign-up == login). Generates a numeric code (`identity.otp.length`, default 5), stores its hash with a TTL (`identity.otp.ttl_minutes`, default 2), and dispatches it. Returns `200 {message, expires_in}`.
      * `POST /api/v1/otp/verify` — body `phone`, `code`, `device_name`. Validates code presence, expiry, and `Hash::check`; on success consumes the code (cleared, single-use, replay-safe) and mints a Sanctum token. Returns `200 {message, user, token}`. Failure → `422` on `code`.
      * **Delivery boundary:** `Modules\Identity\Domain\Contracts\OtpSenderInterface::send(phone, code)`. Bound in `IdentityServiceProvider` to `LogOtpSender` (Infrastructure\Services) — a **log-only placeholder** until the SMS web service is wired in. Swap the binding for a real gateway without touching the flow.
      * **Actions:** `RequestOtp`, `VerifyOtp` (Application\Actions). Old password actions (`RegisterUser`, `LoginUserWithPassword`) and requests (`RegisterRequest`, `LoginRequest`) were **removed**.
  * **Public Cross-Module Contract:**
      * `Modules\Identity\Domain\Contracts\IdentityManagerInterface`: exposes `isAdmin(int $userId): bool`. Available for cross-module role checks, but **prefer direct permission checks via `$user->can('...')` in policies instead** — see Authorization Pattern below.
      * Concrete: `EloquentIdentityManager` (bound in `IdentityServiceProvider::register()`). Internally calls `User::find()->hasRole('admin')` — all Spatie internals stay inside Identity.
  * **Route structure:** user-facing address routes registered under `prefix('addresses')` (plural). Admin user management under `prefix('admin/users')`. Profile self-service under `prefix('profile')`.
  * **Known fix applied:** `UpdateAddressRequest` had `city_id` as `required` instead of `sometimes` — corrected so PATCH requests can update partial fields without supplying city.
  * **Test suite:** `AddressTest` (13), `ProfileTest` (8), `AuthControllerTest` (10 — full OTP request/verify matrix: create-on-request, no-duplicate, invalid phone, verify+token, wrong/expired/unknown code, single-use replay, me, logout), `RolePermissionTest` — all passing.

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
      * `products`: High-level presentation shell with operational tracking (`status: draft, published`) and a main thumbnail (`primary_media_id`).
      * `product_images`: Pivot table supporting **multiple gallery images per product** with a custom display sequence mapping (`sort_order`, `media_id`).
      * `product_variants`: Houses concrete purchasable inventory details tracking unique `sku`, currency integers (`base_price`, `compare_at_price`), attributes JSON arrays, a **per-variant image** (`media_id`), and a **`is_default` boolean** marking exactly one variant per product as the storefront fallback. The single-true invariant is enforced at the application layer.
  * **Domain Models (Step 3):**
      * `Category`, `Product`, `ProductImage`, `ProductVariant` models declared with clean internal relationships. `ProductVariant` casts `is_default` → boolean, `base_price`/`compare_at_price` → integer (Cents Rule), `attributes` → array.
  * **DTOs & Contracts (Step 4):**
      * `CategoryDTO`, `ProductImageDTO`, `ProductVariantDTO` (includes `isDefault: bool`, `basePrice: int`, `compareAtPrice: ?int`), `ProductDTO` (composes image and variant DTO arrays).
      * `CatalogManagerInterface`: full read/write surface. Write: `createCategory`, `updateCategory`, `deleteCategory`, `createProduct`, `updateProduct`, `deleteProduct`, `addProductImage`, `createProductVariant`, `updateProductVariant`, `deleteProductVariant`. Read: `findProduct`, `findProductBySlug`, `findProductAdmin`, `findVariant`, `findVariantBySku`, `getProductsByCategory` (paginated), `getActiveRootCategories` (paginated).
      * `EloquentCatalogManager`: concrete implementation. Uses `getMediaCollection()` for batch URL hydration. Supports pagination on list endpoints via `LengthAwarePaginator`. Bound in `CatalogServiceProvider::register()`.
  * **Application Actions (Step 5):**
      * Create triplet: `CreateCategoryAction`, `CreateProductAction`, `CreateProductVariantAction` handle creation and enforce invariants (Cents Rule, is_default single-true).
      * Update triplet: `UpdateCategoryAction`, `UpdateProductAction`, `UpdateProductVariantAction` handle partial updates with file uploads and invariant enforcement.
      * Delete triplet: `DeleteCategoryAction`, `DeleteProductAction`, `DeleteProductVariantAction` are thin wrappers.
  * **HTTP Layer (Step 6):**
      * 3 Controllers: `CategoriesController`, `ProductsController`, `ProductVariantsController`.
      * 8 Form Requests: `StoreCategoryRequest`, `UpdateCategoryRequest`, `IndexCategoriesRequest`, `StoreProductRequest`, `UpdateProductRequest`, `IndexProductsRequest`, `StoreProductVariantRequest`, `UpdateProductVariantRequest`.
      * 4 API Resources: `CategoryResource`, `ProductResource`, `ProductImageResource`, `ProductVariantResource` — all accept DTOs, no Eloquent models.
  * **Routes & Feature Tests (Step 7):**
      * 20 RESTful routes: POST/GET/PATCH/DELETE for categories, products, variants.
      * Pagination: `getActiveRootCategories` and `getProductsByCategory` return `LengthAwarePaginator` (15 items/page, 1–100 configurable via `per_page` query param, `page` for page number). Scramble auto-documents both params.
  * **Authorization Layer (Step 8 — Permission-based Policies):**
      * **Public routes** (no auth): all GET read endpoints (category list/show, product show/by-slug/by-category, variant show/by-sku).
      * **Protected routes** (`auth:sanctum` only on the route): all write operations (POST/PATCH/DELETE) and `GET /products/{id}/admin`. `auth:sanctum` gives 401 for unauthenticated; policies give 403 for unauthorized.
      * **Policy files** (`Modules\Catalog\Domain\Policies\`): `CategoryPolicy`, `ProductPolicy`, `ProductVariantPolicy`. Each method delegates to `$user->can('catalog.X.Y')`. Typehinted against `Illuminate\Contracts\Auth\Access\Authorizable` — **never** import `Modules\Identity\Domain\Models\User` across the module boundary.
      * **`CatalogAuthServiceProvider`** (`Modules\Catalog\Infrastructure\Providers\`) registers all three policies via `$policies` + `registerPolicies()`. It is booted from `CatalogServiceProvider::register()` via `$this->app->register(CatalogAuthServiceProvider::class)`.
      * **Authorization split**: `FormRequest::authorize()` handles store/update (runs before validation → always 403, never 422, for unauthorized users). `$this->authorize()` in controllers handles destroy and showAdmin (no FormRequest involved). Controllers use the `AuthorizesRequests` trait.
      * **Permissions** seeded in `RolesAndPermissionsSeeder`: `catalog.category.{create,update,delete}`, `catalog.product.{view-admin,create,update,delete}`, `catalog.variant.{create,update,delete}`. Admin role receives all permissions automatically (syncs all). Customer role receives none of these.
      * Authorization is **permission-based, not role-based** — any user granted a specific permission can perform that action, independent of role.
  * **Test Suite (Step 9 — Final):**
      * 4 feature test classes: `CategoriesTest`, `ProductsTest`, `ProductVariantsTest`, `CatalogAuthorizationTest` — **95 tests total**.
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

---

## 6. Completed & Ready

| Module | Status | Tests |
|---|---|---|
| Identity | ✅ Complete (passwordless OTP) | AddressTest, ProfileTest, AuthControllerTest (10), RolePermissionTest |
| Media | ✅ Complete | 12 passing (MediaUploadTest) + existing MediaManagerTest |
| Catalog | ✅ Complete | 95 passing across 4 test classes |
| Inventory | ✅ Complete | 24 passing across 2 test classes |
| Cart | ✅ Complete | 15 passing (CartTest) |

**Total test suite: 199 tests, 522 assertions — all green.**

**Next module in queue:** Order or Payment — pending product roadmap review.

