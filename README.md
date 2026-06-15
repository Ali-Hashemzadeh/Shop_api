# Shop API

A production-grade e-commerce backend built as a **modular monolith** on Laravel 12. Each business domain lives in its own self-contained module with hard isolation boundaries, making the codebase trivially splittable into microservices later — without paying microservices DevOps costs today.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 12 |
| Language | PHP 8.2+ |
| Authentication | Laravel Sanctum (token-based) |
| Authorization | Spatie Laravel-Permission (RBAC) |
| API Documentation | Dedoc Scramble (auto-generated from code) |
| Testing | PHPUnit 11 |
| Storage | Laravel Storage (local disk via public symlink) |

---

## Architecture: Modular Monolith

The application is divided into independent modules under `Modules/`. Each module is a self-contained bounded context that owns its own models, migrations, routes, business logic, and service provider.

### Module Isolation Rules

1. **No model sharing across modules.** A module never imports another module's Eloquent model.
2. **Contract-based communication.** Cross-module calls happen exclusively through published `Domain/Contracts/` interfaces resolved from the service container.
3. **DTOs at the boundary.** Data crossing a module wall is carried in immutable Data Transfer Objects, never raw Eloquent models.
4. **No cross-module database joins.** Each module queries only its own tables.

### Module Directory Layout

```
Modules/
└── [ModuleName]/
    ├── Domain/
    │   ├── Models/        # Eloquent models (private to this module)
    │   ├── Contracts/     # Public interface contracts for other modules
    │   ├── DTOs/          # Immutable data carriers crossing the boundary
    │   └── Policies/      # Laravel authorization policies
    ├── Application/
    │   └── Actions/       # Single-responsibility command handlers
    └── Infrastructure/
        ├── Http/           # Controllers, Form Requests, API Resources, Middleware
        ├── Persistence/    # Migrations, Repositories, Seeders
        ├── Providers/      # Module service provider
        └── Routes/         # Module route file
```

---

## Engineering Standards

### Financial Integrity — The Cents Rule
All monetary values are stored and processed as **raw integers** representing the smallest currency unit (rials for Persian locale). Floats are completely forbidden for financial fields. The HTTP layer casts whole-number strings to `int` via `prepareForValidation()` before the `integer` validation rule fires, so form-encoded requests work naturally.

### Loose Media Coupling
Tables outside the Media module never use cascading foreign keys to the `media` table. They store `media_id` as a plain `unsignedBigInteger` nullable column. This lets the Media module evolve independently without breaking other schemas.

### Test-Driven Mutations
Every Action and Repository method that mutates state has matching feature tests covering: happy path, validation failure, not-found (404), invariant enforcement, and authorization (401/403).

---

## Modules

### Identity (Complete)
Handles authentication, user profiles, multi-role RBAC, shipping provinces/cities, and user delivery addresses.

- **Key entities:** `User`, `Address`, `Province`, `City`
- **Public contract:** `IdentityManagerInterface::isAdmin(int $userId): bool` — the only authorized way for other modules to check user privilege without importing Identity's models.
- **Auth pattern:** Sanctum tokens + Spatie roles (`admin`, `customer`)

### Media (Complete)
Lightweight, high-performance file upload handler and storage ledger.

- **Key contract:** `MediaManagerInterface` — accepts an `UploadedFile`, persists it to the local disk, and returns a `MediaDTO` with the public URL.
- **Pattern:** No other module handles raw file I/O; they delegate to `MediaManagerInterface` and store the returned `media_id`.
- **Endpoints:** `POST /api/v1/media` (standalone upload, requires `media.upload` permission) and `DELETE /api/v1/media/{id}` (requires `media.delete` permission). Enables the pre-upload SPA flow: upload → receive `media_id` → pass to any Catalog write endpoint.

### Catalog (Complete)
Controls the storefront presentation layer: hierarchical categories, products with multi-image galleries, and purchasable product variants.

- **Key entities:** `Category` (infinite nesting via `parent_id`), `Product` (`draft`/`published` status), `ProductImage` (gallery with sort order), `ProductVariant` (unique SKU, integer prices, JSON attributes, per-variant image, `is_default` single-true invariant)
- **Key contract:** `CatalogManagerInterface` — full read/write surface consumed by higher-level modules (e.g. Orders, Inventory)
- **Authorization:** Public read endpoints require no auth. Write endpoints and the admin product view require `auth:sanctum`; authorization is permission-based (`catalog.category.*`, `catalog.product.*`, `catalog.variant.*`) enforced via Laravel policies — any user granted a specific permission can act, independent of role.
- **Pagination:** List endpoints return `LengthAwarePaginator` with a `{data, links, meta}` envelope. `per_page` (1–100) and `page` are documented automatically by Scramble.

---

## API Overview

Base prefix: `/api/v1`

### Media (`auth:sanctum` + permission required)

| Method | Endpoint | Permission | Description |
|---|---|---|---|
| `POST` | `/media` | `media.upload` | Upload a file; returns `{id, url, …}` |
| `DELETE` | `/media/{id}` | `media.delete` | Delete file and ledger record |

### Catalog — Public (no auth)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/catalog/categories/roots` | Paginated list of active root categories |
| `GET` | `/catalog/categories/{id}` | Single category by ID |
| `GET` | `/catalog/categories/{categoryId}/products` | Paginated published products in a category |
| `GET` | `/catalog/products/{id}` | Single published product (with gallery + variants) |
| `GET` | `/catalog/products/slug/{slug}` | Product by URL slug |
| `GET` | `/catalog/variants/{variantId}` | Single variant by ID |
| `GET` | `/catalog/variants/sku/{sku}` | Variant by SKU |

### Catalog — Protected (`auth:sanctum` + catalog permission required)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/catalog/products/{id}/admin` | Product by ID regardless of publish status |
| `POST` | `/catalog/categories` | Create category |
| `PATCH` | `/catalog/categories/{id}` | Update category |
| `DELETE` | `/catalog/categories/{id}` | Delete category |
| `POST` | `/catalog/products` | Create product |
| `PATCH` | `/catalog/products/{id}` | Update product |
| `DELETE` | `/catalog/products/{id}` | Delete product |
| `POST` | `/catalog/products/{productId}/variants` | Add variant to product |
| `PATCH` | `/catalog/variants/{variantId}` | Update variant |
| `DELETE` | `/catalog/variants/{variantId}` | Delete variant |

Auto-generated interactive API docs are available at `/docs/api` when running locally (powered by Scramble).

---

## Getting Started

### Requirements

- PHP 8.2+
- Composer
- SQLite (default for local) or MySQL/PostgreSQL

### Install

```bash
git clone <repo-url> shop-api
cd shop-api
composer setup
```

The `composer setup` script installs dependencies, copies `.env.example` → `.env`, generates the app key, and runs migrations.

### Environment

Copy `.env.example` and configure your database and filesystem:

```bash
cp .env.example .env
php artisan key:generate
```

Key `.env` values:

```env
APP_URL=http://localhost:8000

DB_CONNECTION=sqlite
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_DATABASE=shop_api

FILESYSTEM_DISK=public
```

Create the storage symlink:

```bash
php artisan storage:link
```

### Run

```bash
composer dev
```

This starts the Laravel dev server, queue worker, log tail, and Vite in parallel via concurrently.

Or individually:

```bash
php artisan serve
php artisan queue:listen
```

---

## Testing

```bash
composer test
```

This clears config cache and runs the full PHPUnit suite against the in-memory SQLite database.

Tests are organized by module under `tests/Feature/`:

```
tests/
└── Feature/
    ├── Catalog/
    │   ├── CategoriesTest.php           # Category CRUD + validation
    │   ├── ProductsTest.php             # Product CRUD + status + gallery
    │   ├── ProductVariantsTest.php      # Variant CRUD + Cents Rule + is_default invariant
    │   └── CatalogAuthorizationTest.php # Auth matrix: 401 / 403 / public access
    ├── Identity/
    │   ├── AddressTest.php              # Address CRUD + ownership + admin override
    │   ├── ProfileTest.php             # Profile self-service + admin user management
    │   ├── AuthControllerTest.php       # Login / token issuance
    │   └── RolePermissionTest.php       # Role and permission assignment
    └── Media/
        ├── MediaManagerTest.php         # MediaManagerInterface contract tests
        └── MediaUploadTest.php          # Upload/delete endpoints + auth boundaries
```

---

## Module Roadmap

| Module | Status |
|---|---|
| Identity | Complete |
| Media | Complete |
| Catalog | Complete |
| Inventory | Planned |
| Order | Planned |
| Payment | Planned |

---

## License

MIT
