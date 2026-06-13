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
    ├── Application/
    │   └── Actions/      # Single-responsibility business logic handlers
    └── Infrastructure/
        ├── Http/         # Controllers, Request Validators, API Resources
        ├── Persistence/  # Migrations, Repositories
        └── Providers/    # Module-specific Service Providers
```

---

## 2. Inviolable Engineering Rules

* **Financial Integrity (The Cents Rule):** All monetary values (prices, discounts, taxes) must be processed and stored in the database exclusively as raw integers representing the smallest currency unit (e.g., cents: `$19.99` is stored as `1999`). Floating-point numbers are completely barred for financial attributes.
* **Loose Media Coupling:** To keep modules completely decoupled, tables outside the Media module must **never** use cascading database foreign keys pointing to the `media` table. Instead, store them as standard `unsignedBigInteger('media_id')->nullable()` columns.
* **Test-Driven Modifications:** Any block that mutates data state (Actions, Repositories, Managers) must have matching Feature/Unit tests covering success and failure edge-cases.
* **Storage Environment:** The application relies 100% on fast, streamlined local filesystem storage using Laravel's native `Storage` facade mapping to the `public` disk stream via symlink. Database records do not track target storage drives.

---

## 3. Current Module Ecosystem Ledger

### 🔒 1. Identity Module (Status: Active & Complete)
* **Responsibility:** Multi-role authentication, user profile management, shipping location matrices, and user addresses.
* **Key Entities:** `User`, `Address`, `Province`, `City`.
* **State:** Fully functional, using decoupled Eloquent repositories bound to service contracts (`UserRepositoryInterface`, `AddressRepositoryInterface`).

### 📁 2. Media Module (Status: Active & Complete)
* **Responsibility:** Lightweight, high-performance physical file uploads and tracking ledger.
* **Key Interfaces & Artifacts:**
    * `Modules\Media\Domain\Contracts\MediaManagerInterface`: The only entry point used by other modules to handle files.
    * `Modules\Media\Domain\DTOs\MediaDTO`: The immutable object returned containing the absolute accessible public URL via `Storage::url()`.
    * `Modules\Media\Infrastructure\Persistence\Repositories\LocalMediaManager`: Concrete implementation executing local disk file saves and tracking log generation.

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
    * `CatalogManagerInterface`: full read/write surface. Write: `createCategory`, `updateCategory`, `deleteCategory`, `createProduct`, `updateProduct`, `deleteProduct`, `addProductImage`, `createProductVariant`, `updateProductVariant`, `deleteProductVariant`, `updateVariantPrice`. Read: `findProduct`, `findProductBySlug`, `findProductAdmin`, `findVariant`, `findVariantBySku`, `getProductsByCategory` (paginated), `getActiveRootCategories` (paginated).
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
    * 29 comprehensive feature tests covering CRUD happy paths, validation errors (including Cents Rule), 404 scenarios, invariant enforcement.
    * Pagination: `getActiveRootCategories` and `getProductsByCategory` return `LengthAwarePaginator` (15 items/page, 1–100 configurable via `per_page` query param). Scramble auto-documents the `per_page` param.

---

## 4. Completed & Ready

✅ **Catalog module is 100% complete and tested.** All three layers (Domain/Application/Infrastructure) are in place and feature-tested.

**Next module in queue:** Inventory, Order, or Payment — pending product roadmap review.

