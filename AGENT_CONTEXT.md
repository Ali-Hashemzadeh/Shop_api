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

### 🏷️ 3. Catalog Module (Status: IN PROGRESS - Sprint Step 5 Complete)
* **Responsibility:** Control storefront presentation layout including infinite hierarchical categories, parent products, multi-image product media galleries, and purchasable product variant options.
* **Schema Layout (Step 2 Complete):**
    * `categories`: Supports nesting (`parent_id`) and holds a loose asset reference (`media_id`).
    * `products`: High-level presentation shell with operational tracking (`status: draft, published`) and a main thumbnail (`primary_media_id`).
    * `product_images`: Pivot table supporting **multiple gallery images per product** with a custom display sequence mapping (`sort_order`, `media_id`).
    * `product_variants`: Houses concrete purchasable inventory details tracking unique `sku`, currency integers (`base_price`, `compare_at_price`), attributes JSON arrays, a **per-variant image** (`media_id`), and a **`is_default` boolean** marking exactly one variant per product as the storefront fallback. The single-true invariant is enforced at the application layer.
* **Domain Models (Step 3 Complete):**
    * `Category`, `Product`, `ProductImage`, `ProductVariant` models declared with clean internal relationships. `ProductVariant` casts `is_default` → boolean, `base_price`/`compare_at_price` → integer (Cents Rule), `attributes` → array.
* **DTOs & Contracts (Step 4 Complete):**
    * `CategoryDTO`, `ProductImageDTO`, `ProductVariantDTO` (includes `isDefault: bool`, `basePrice: int`, `compareAtPrice: ?int`), `ProductDTO` (composes image and variant DTO arrays).
    * `CatalogManagerInterface`: full write/read surface. Write: `createCategory`, `createProduct`, `addProductImage`, `createProductVariant`, `updateVariantPrice`. Read: `findProduct`, `findProductBySlug`, `findProductAdmin`, `findVariant`, `findVariantBySku`, `getProductsByCategory`, `getActiveRootCategories`.
    * `EloquentCatalogManager`: concrete implementation. Uses `getMediaCollection()` for batch URL hydration. Bound in `CatalogServiceProvider::register()`.
* **Application Actions (Step 5 Complete):**
    * `CreateCategoryAction::handle(array, ?UploadedFile): CategoryDTO` — auto-generates slug via `Str::slug`; accepts uploaded file or existing `media_id`.
    * `CreateProductAction::handle(array, ?UploadedFile, UploadedFile[]): ProductDTO` — creates product shell, uploads primary thumbnail and ordered gallery in a single `DB::transaction`; array index becomes `sort_order`.
    * `CreateProductVariantAction::handle(int, array, ?UploadedFile): ProductVariantDTO` — enforces Cents Rule with hard `InvalidArgumentException` on non-integer prices; enforces `is_default` single-true invariant inside a `DB::transaction` by unsetting any prior default before writing the new variant.

---

## 4. Active Workflow & Next Instructions

The Catalog module is being built from total scratch. Steps 1 through 5 are fully written and committed.

### Your Immediate Objective:
Proceed directly to **Step 6** of the Catalog Module design blueprint:
1. **Build HTTP Layer:** Create Controllers, Form Request validators, and API Resources under `Modules/Catalog/Infrastructure/Http/`. Each Controller method should resolve the relevant Action from the container and call its `handle()` method.
2. **Form Requests:** Validate that `base_price` and `compare_at_price` are `integer` type (not numeric string) so the Cents Rule guard in `CreateProductVariantAction` is never triggered by malformed HTTP input.
3. **API Resources:** Transform DTOs into JSON. Monetary values stay as integers — no float division at this layer. Image URLs come directly from DTO string fields.

### Implementation Checklist Rules for Agent:
* Controllers must not instantiate Actions directly — resolve them via the service container (`app(CreateCategoryAction::class)`), or inject them via constructor.
* API Resources accept DTOs, not raw Eloquent models. Never import `Modules\Catalog\Domain\Models\*` inside the Http layer.
* Do not attempt to add direct database foreign keys from `Catalog` tables to the `media` table.
* Ensure all files are properly typed with accurate namespaces matching the modular layout structure.
