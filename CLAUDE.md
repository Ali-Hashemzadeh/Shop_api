# CLAUDE.md

Guidance for Claude Code (and any AI agent) working in this repository. Read this
first, then defer to `AGENT_CONTEXT.md` for the authoritative, always-current module
ledger. `README.md` is the human-facing overview and `CHANGELOG.md` records what
changed and why.

> **Source-of-truth order:** `AGENT_CONTEXT.md` (architecture law + live module state)
> → this file (how we work) → `README.md` → `CHANGELOG.md`. If this file and
> `AGENT_CONTEXT.md` ever disagree, `AGENT_CONTEXT.md` wins — update this file to match.

---

## 1. What this project is

A production-grade **e-commerce backend** built as a **modular monolith** on **Laravel 12 / PHP 8.2+**.
Every business domain lives in its own self-contained module under `Modules/` with hard
isolation boundaries, so the system can be split into microservices later without paying
the DevOps cost today.

| Layer | Technology |
|---|---|
| Framework | Laravel 12 |
| Language | PHP 8.2+ |
| Auth (authn) | Laravel Sanctum (token-based) |
| Auth (authz) | Spatie laravel-permission (RBAC, permission-based) |
| API docs | Dedoc Scramble (auto-generated, `/docs/api`) |
| Testing | PHPUnit 11 |
| Storage | Laravel `Storage`, local `public` disk via symlink |
| DB | SQLite local / in-memory for tests; MySQL/Postgres in prod |

---

## 2. Architecture: the Modular Monolith

Each folder in `Modules/` is an **independent bounded context** that owns its own models,
migrations, routes, business logic, and service provider. Treat each module as if it were
a separate service.

### The inviolable enclosure rules

1. **Hard module isolation.** A module is a black box to every other module.
2. **No model sharing.** A module **never** imports another module's Eloquent model,
   listens to another module's internal events, or runs raw DB joins against another
   module's tables.
3. **Cross-module communication only via Contracts + DTOs.** All interaction crosses the
   domain wall exclusively through published `Domain/Contracts/` interfaces resolved from
   the Laravel service container, returning immutable **DTOs** — never raw models.
4. **No cross-module DB joins.** Each module queries only its own tables.

### Module directory blueprint (DDD / Hexagonal)

```
Modules/
└── [ModuleName]/
    ├── Domain/
    │   ├── Models/        # Eloquent models — PRIVATE to this module
    │   ├── Contracts/     # Public interfaces other modules may depend on
    │   ├── DTOs/          # Immutable data carriers that cross the boundary
    │   └── Policies/      # Authorization policies
    ├── Application/
    │   └── Actions/       # Single-responsibility business-logic handlers
    └── Infrastructure/
        ├── Http/          # Controllers, Requests (validators), Resources, Middleware
        ├── Persistence/   # Migrations, Repositories, Seeders
        ├── Providers/     # Module service provider(s)
        └── Routes/        # Module route file(s)
```

### How a module wires into the app

- Namespacing is PSR-4: `Modules\` → `Modules/` (see `composer.json`).
- Each module has a `*ServiceProvider` registered in **`bootstrap/providers.php`**. When
  you add a new module, register its provider there.
- The provider's `register()` **binds Contract → concrete** (e.g.
  `$this->app->bind(CatalogManagerInterface::class, EloquentCatalogManager::class)`) and
  may register a child auth provider (e.g. `CatalogAuthServiceProvider`).
- The provider's `boot()` loads routes and migrations:
  `$this->loadRoutesFrom(__DIR__.'/../Routes/api.php')` and
  `$this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations')`.
- Module route files build their own full prefix, e.g.
  `Route::middleware('api')->prefix('api/v1/catalog')->group(...)`.

---

## 3. Inviolable engineering rules

These are non-negotiable. Violating one is a bug even if tests pass.

- **The Cents Rule (financial integrity).** All monetary values (prices, discounts,
  taxes) are stored and processed as **raw integers** in the smallest currency unit
  (rials). **Floats are forbidden** for any financial field. Eloquent casts money columns
  to `integer`; the HTTP layer casts whole-number strings to `int` in
  `prepareForValidation()` before the `integer` rule fires so form-encoded requests work.
- **Loose Media coupling.** Tables outside the Media module **must not** use cascading FKs
  to the `media` table. Store the reference as a plain
  `unsignedBigInteger('media_id')->nullable()` column. The DB does not track storage
  drives.
- **Test-driven mutations.** Any code that mutates state (Actions, Repositories, Managers)
  **must** ship with matching Feature/Unit tests covering success **and** failure edge
  cases (validation, 404, invariant enforcement, 401/403).
- **Local-storage only.** The app uses Laravel's native `Storage` facade on the `public`
  disk via symlink (`php artisan storage:link`). No driver is persisted in the DB.
- **Respect the module boundary in typehints.** Policies and cross-module code typehint
  against framework contracts (e.g. `Illuminate\Contracts\Auth\Access\Authorizable`),
  **never** another module's concrete model (e.g. `Modules\Identity\Domain\Models\User`).

---

## 4. How we code (conventions to mirror)

Match the surrounding code. Concrete patterns used throughout:

- **`declare(strict_types=1);`** at the top of new Application/Domain PHP files (see
  Actions). Match the file you're editing if it differs.
- **Constructor property promotion + `readonly`** for injected dependencies and DTO
  fields:
  ```php
  public function __construct(
      private readonly CatalogManagerInterface $catalog,
      private readonly MediaManagerInterface $media,
  ) {}
  ```
- **Actions** expose a single `handle(...)` method, are single-responsibility, and wrap
  multi-step writes in `DB::transaction(fn () => ...)`. They depend on **Contracts**, not
  concrete repositories.
- **DTOs** are immutable (`public readonly` props) and provide a static
  `fromModel(Model $m, ...): self` named-arguments factory. They carry primitives and a
  resolved `imageUrl`/public URL — never the Eloquent model.
- **Controllers** are thin: resolve Actions + the module's Manager contract via the
  constructor, delegate to the Action, and return an API Resource wrapped in
  `response()->json(new XResource($dto), 201)`. They use the `AuthorizesRequests` trait
  for `$this->authorize(...)` on `destroy`/`showAdmin`.
- **Form Requests** own validation **and** authorization for store/update.
  `authorize()` returns a **permission** check —
  `(bool) $this->user()?->can('catalog.product.create')` — so unauthorized users get
  **403 before validation** (never a 422 bleed-through).
- **API Resources** accept **DTOs**, never Eloquent models.
- **Authorization is permission-based, not role-based.** Policies delegate to
  `$user->can('module.entity.action')`. Any user granted a permission can act,
  independent of role. Roles (`admin`, `customer`) are just permission bundles seeded by
  the module seeders; `admin` receives all permissions.
- **Routes:** public read endpoints carry no auth; write + admin-read endpoints sit inside
  a `Route::middleware('auth:sanctum')` group. `auth:sanctum` yields **401** for
  unauthenticated; policies yield **403** for unauthorized; public routes never return
  401/403.
- **Rate limiting (always apply):** Every route group must carry a named throttle middleware.
  Five named limiters are defined in `AppServiceProvider::configureRateLimiting()`:
  `otp` (5/min per IP — OTP endpoints only), `public` (120/min per IP — open reads),
  `inventory-batch` (30/min per IP — unauthenticated batch), `uploads` (20/min per user —
  file upload), `api` (60/min per user, 30/min per IP fallback — all authenticated routes
  and guest cart). When adding a new module, choose the appropriate limiter and apply it at
  the route-group level. Exceeding the limit returns **429** with a `Retry-After` header.
- **Pagination:** list endpoints return a `LengthAwarePaginator` (`{data, links, meta}`
  envelope), default 15/page, `per_page` clamped 1–100, `page` for the page number — both
  auto-documented by Scramble.
- **Config over constants:** tunables live in `config/*.php` (e.g.
  `config/identity.php` → `otp.length`, `otp.ttl_minutes`) backed by `.env` keys.
- **Code style:** Laravel Pint is installed (dev) alongside `.editorconfig` and
  `.styleci.yml`. Run Pint before finishing edits.

---

## 5. Module ledger (current state)

> Always re-check section 3 of `AGENT_CONTEXT.md` — it is the live ledger.

| Module | Status | Notes |
|---|---|---|
| **Identity** | ✅ Complete | Passwordless OTP auth, profiles, RBAC, provinces/cities, addresses |
| **Media** | ✅ Complete | File upload ledger + standalone upload/delete endpoints |
| **Catalog** | ✅ Complete | Categories, products, galleries, variants, atomic nested create, variant upsert on update, variant `type` (`image`/`color`), admin all-status product index — 128 tests |
| **Inventory** | ✅ Complete | Stock tracking, reservation lifecycle, append-only ledger — 24 tests |
| **Cart** | ✅ Complete | Guest + auth carts, stock-validated add/update, Catalog price enrichment — 15 tests |
| **Orders** | 🚧 Scaffolded | Directory skeleton exists under `Modules/Orders/`; provider **not yet** in `bootstrap/providers.php` and not documented in README/AGENT_CONTEXT — confirm scope before building |
| Payment | 📋 Planned | Pending roadmap review |

**Test suite baseline: 245 tests / 688 assertions, all green.**

### Identity — key facts
- **Passwordless OTP, phone-based, unified register+login** (sign-up == login).
  `POST /api/v1/otp/request` (`phone` `09xxxxxxxxx`, optional `name`) finds-or-creates the
  user (assigns `customer` on first contact) and sends a hashed, short-TTL numeric code.
  `POST /api/v1/otp/verify` (`phone`, `code`, `device_name`) consumes the single-use code
  and mints a Sanctum token.
- OTP is stored **hashed** (`otp_code`, hidden) with `otp_expires_at`; verified via
  `Hash::check`, consumed on success (replay-safe).
- **Delivery boundary:** `OtpSenderInterface::send(phone, code)`. In production, bound to
  `SmsIrOtpSender` (calls `POST https://api.sms.ir/v1/send/verify`, template-based only,
  converts `09XXXXXXXXX` → `98XXXXXXXXX`). Falls back to `LogOtpSender` when
  `SMSIR_API_KEY` is empty — no code changes needed between environments. Config lives in
  `config/identity.php` under the `sms` key (`api_key`, `template_id`, `code_param`).
- **Public contract:** `IdentityManagerInterface::isAdmin(int $userId): bool` — but prefer
  direct `$user->can('...')` permission checks in policies over role checks.

### Media — key facts
- **Only entry point:** `MediaManagerInterface` —
  `upload(UploadedFile, string $folder): MediaDTO`, `getMedia`, `getMediaCollection`,
  `delete`. No other module performs raw file I/O.
- `MediaDTO` carries the public URL via `Storage::url()`.
- **One usage flow:** pre-upload (`POST /api/v1/media` → get `media_id` → pass `primary_media_id` / `gallery_media_ids` / `variants.*.media_id` to a Catalog write endpoint). Catalog product endpoints no longer accept inline file uploads directly.

### Catalog — key facts
- Entities: `Category` (infinite nesting via `parent_id`), `Product` (`draft`/`published`,
  `primary_media_id`), `ProductImage` (gallery, `sort_order`), `ProductVariant` (auto-generated
  `sku` format `bdp{productId}-v{n}` — **never accepted from client input**, integer
  `base_price`/`compare_at_price`, JSON `attributes`, per-variant
  `type` (`image` or `color`, required), `media_id`, `is_default` **single-true invariant enforced at the application layer**).
- **Contract:** `CatalogManagerInterface` — full read/write surface for higher modules.
- **Atomic nested create:** `POST /api/v1/catalog/products` accepts an optional `variants`
  array. When provided, the product and all variants are created together inside a single
  `DB::transaction`. Validation enforces that exactly one variant has `is_default: true`.
  Omitting `variants` is valid — the standalone variant routes are unchanged.
- **Variant upsert on update:** `PATCH /api/v1/catalog/products/{id}` also accepts an
  optional `variants` array with upsert-by-ID semantics — known `id` already on this product →
  `updateProductVariant`; missing or unknown `id` → `createProductVariant` with auto-generated
  SKU; variants absent from the array are untouched. Invariant: at most one submitted variant
  may have `is_default: true`. All variant mutations are wrapped in the same `DB::transaction`
  as the product update.
- **Admin product index:** `GET /api/v1/catalog/products/admin` (auth + `catalog.product.view-admin`)
  returns products in **every** status (draft + published), newest first, paginated. Filters:
  `status` (`draft`/`published`), `category_id`, `min_price`/`max_price` (default variant), and
  `search` (LIKE on title, description, slug, or variant SKU). The public `GET /products/{id}` route
  is `whereNumber`-constrained so it does not shadow `/products/admin`.
- **No inline file uploads on product endpoints.** Pass `primary_media_id`,
  `gallery_media_ids`, or `variants.*.media_id` (pre-uploaded via `POST /api/v1/media`).
- Permissions: `catalog.category.{create,update,delete}`,
  `catalog.product.{view-admin,create,update,delete}`, `catalog.variant.{create,update,delete}`.

### Inventory — key facts
- Tracks `quantity` and `reserved_quantity` per SKU. Available stock = `quantity − reserved_quantity`.
- All mutations use `DB::transaction()` + `lockForUpdate()` to prevent oversell race conditions.
- **Contract:** `InventoryManagerInterface` — `getStockBySku`, `getBatchStockBySkus`, `adjustStock`, `reserveStock`, `commitReservation`, `releaseReservation`.
- Exceptions: `StockNotFoundException` (unknown SKU), `InsufficientStockException` (available < requested).
- Permissions: `inventory.stock.manage`, `inventory.ledger.view`.

### Cart — key facts
- **Dual identity:** authenticated users get a `user_id`-keyed cart; guests use a `session_id` (sent as `X-Session-Id` request header — auto-generated UUID if absent, echoed back as `X-Cart-Session-Id` response header).
- **Cart identification middleware** (`cart.identify`) runs on all cart routes. It calls `auth('sanctum')` without requiring it — no 401 for guests.
- **Stock validation** on every add/update via `InventoryManagerInterface::getStockBySku()`. The action re-throws Inventory exceptions as Cart-domain exceptions so the controller stays isolated.
- **Price enrichment** on every `getCart()` via `CatalogManagerInterface::findVariantBySku()` — all prices are integers (rials, Cents Rule). `lineTotal` = `quantity × basePrice`.
- **No permissions required** — cart operations are self-service; ownership is enforced by the middleware.
- **Contract:** `CartManagerInterface` — `findOrCreateCart`, `getCart`, `addItem`, `removeItem`, `updateQuantity`, `clearCart`.

---

## 6. Commands

```bash
composer setup     # install deps, copy .env, key:generate, migrate, npm install + build
composer dev       # serve + queue:listen + pail (logs) + vite, all in parallel
composer test      # config:clear then full PHPUnit suite (in-memory SQLite)

php artisan serve
php artisan queue:listen
php artisan storage:link          # required once for public-disk media
php artisan test --filter=ProductsTest
./vendor/bin/pint                 # code style (run before finishing)
```

API docs (Scramble) are served at **`/docs/api`** when running locally.

---

## 7. Testing

- Tests live under `tests/Feature/<Module>/` (e.g. `tests/Feature/Catalog/ProductsTest.php`).
- The base `Tests\TestCase` provides helpers — **use these instead of hand-rolling auth/seeding**:
  - `actingAsAdmin()` / `actingAsCustomer()` — create a user, assign the role, `Sanctum::actingAs`.
  - `seedIdentityRolesAndPermissions()`, `seedCatalogPermissions()`, `seedMediaPermissions()`.
- Every state-mutating change needs tests for: happy path, validation failure, 404,
  invariant enforcement (Cents Rule, `is_default` single-true, slug uniqueness), and the
  auth matrix (401 unauthenticated / 403 unauthorized / 200 public).
- Run `composer test` (or a focused `--filter`) and keep the suite green before finishing.

---

## 8. Adding a new module — checklist

1. Scaffold the `Domain / Application / Infrastructure` blueprint (section 2).
2. Define `Domain/Contracts/*Interface` (the public API) + immutable `Domain/DTOs/*`.
3. Implement Eloquent models (private), repositories, and the concrete Manager under
   `Infrastructure/Persistence/`.
4. Write Actions (`handle()`, transactional, depend on Contracts) for every mutation.
5. Add `Http/Controllers` (thin), `Http/Requests` (validation + permission `authorize()`),
   `Http/Resources` (accept DTOs).
6. Add `Domain/Policies/*` delegating to `$user->can('module.entity.action')`, typehinted
   against `Authorizable`.
7. Define routes in `Infrastructure/Routes/api.php` with the full
   `api/v1/<module>` prefix; gate writes behind `auth:sanctum`.
8. Seed permissions in a module seeder; grant them to `admin`.
9. Create the `*ServiceProvider` (bind contracts in `register()`, load routes/migrations +
   register policies in `boot()`) and **register it in `bootstrap/providers.php`**.
10. Write feature tests covering the full matrix. Run `composer test` + Pint.
11. Update `AGENT_CONTEXT.md` (ledger), `README.md`, and `CHANGELOG.md`.

---

## 9. Definition of done

- Module boundaries respected (no cross-module models/joins; only Contracts + DTOs).
- Cents Rule and Loose-Media-Coupling honored.
- Authorization is permission-based via policies/Form Requests; 401/403/public matrix correct.
- Tests added/updated and `composer test` is fully green.
- Pint clean.
- `AGENT_CONTEXT.md`, `README.md`, and `CHANGELOG.md` updated to reflect the change.
