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
* **Responsibility:** Multi-role authentication, user profile management, shipping location matrices, and user addresses.
* **Key Entities:** `User`, `Address`, `Province`, `City`.
* **State:** Fully functional, using decoupled Eloquent repositories bound to service contracts (`UserRepositoryInterface`, `AddressRepositoryInterface`).
* **Public Cross-Module Contract:**
    * `Modules\Identity\Domain\Contracts\IdentityManagerInterface`: exposes `isAdmin(int $userId): bool`. Available for cross-module role checks, but **prefer direct permission checks via `$user->can('...')` in policies instead** — see Authorization Pattern below.
    * Concrete: `EloquentIdentityManager` (bound in `IdentityServiceProvider::register()`). Internally calls `User::find()->hasRole('admin')` — all Spatie internals stay inside Identity.
* **Route structure:** user-facing address routes registered under `prefix('addresses')` (plural). Admin user management under `prefix('admin/users')`. Profile self-service under `prefix('profile')`.
* **Known fix applied:** `UpdateAddressRequest` had `city_id` as `required` instead of `sometimes` — corrected so PATCH requests can update partial fields without supplying city.
* **Test suite:** `AddressTest` (13), `ProfileTest` (8), `AuthControllerTest` (1), `RolePermissionTest` — all passing.

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

---

## 4. Completed & Ready

| Module | Status | Tests |
|---|---|---|
| Identity | ✅ Complete | 23 passing |
| Media | ✅ Complete | 12 passing (MediaUploadTest) + existing MediaManagerTest |
| Catalog | ✅ Complete | 95 passing across 4 test classes |

**Total test suite: 146 tests, 382 assertions — all green.**

**Next module in queue:** Inventory, Order, or Payment — pending product roadmap review.

