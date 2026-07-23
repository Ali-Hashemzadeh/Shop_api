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
Handles OTP + password authentication, user profiles, multi-role RBAC, shipping provinces/cities, and user delivery addresses.

- **Key entities:** `User`, `Address` (province/city/postal code plus a map pin — `latitude`/`longitude`, required on create, and an optional `map_address` line), `Province`, `City`
- **Public contract:** `IdentityManagerInterface::isAdmin(int $userId): bool` — the only authorized way for other modules to check user privilege without importing Identity's models.
- **Auth pattern:** Split-auth onboarding over Sanctum tokens + Spatie roles (`admin`, `customer`). `POST /api/v1/auth/check-user` (`phone_number`) returns `is_new_user` + `allowed_methods`: unknown phones get `["otp"]` (must verify ownership first), known phones get `["password", "otp"]`. `POST /api/v1/otp/request` (phone `09xxxxxxxxx`, optional `name`) finds-or-creates the user and sends a hashed, short-TTL code; `POST /api/v1/otp/verify` (phone, code, device_name, optional `name`/`password`) consumes the single-use code, optionally sets a hashed password, and mints a token. Sign-up and login are the same flow.
- **Password login:** `POST /api/v1/auth/login-password` (`phone_number`, `password`, optional `device_name`) verifies via `Hash::check()` and mints a Sanctum token. Bad phone / wrong password / password-less account all return a generic **401 `Invalid credentials.`** Passwords are stored hashed (`Hash::make()`); accounts may remain OTP-only (`password` is nullable).
- **OTP delivery:** swappable `OtpSenderInterface`, bound to a log-only `LogOtpSender` placeholder until the SMS gateway is connected. Tunables in `config/identity.php` → `otp.length`, `otp.ttl_minutes`.

### Media (Complete)
Lightweight, high-performance file upload handler and storage ledger.

- **Key contract:** `MediaManagerInterface` — accepts an `UploadedFile`, persists it to the local disk, and returns a `MediaDTO` with the public URL.
- **Pattern:** No other module handles raw file I/O; they delegate to `MediaManagerInterface` and store the returned `media_id`.
- **Endpoints:** `POST /api/v1/media` (standalone upload, requires `media.upload` permission) and `DELETE /api/v1/media/{id}` (requires `media.delete` permission). Enables the pre-upload SPA flow: upload → receive `media_id` → pass to any Catalog write endpoint.

### Catalog (Complete)
Controls the storefront presentation layer: hierarchical categories, products with multi-image galleries, and purchasable product variants.

- **Key entities:** `Category` (infinite nesting via `parent_id`), `Product` (`draft`/`published` status), `ProductImage` (gallery with sort order), `ProductVariant` (unique SKU, integer prices, JSON attributes, per-variant image, `is_default` single-true invariant)
- **Per-order variant limit:** ProductVariant.max_quantity_per_order is nullable (
ull = unlimited by Catalog, minimum configured value 1). It applies independently to each cart/order; previous Orders are not counted. Variant reads expose it and an effective maximum bounded by current available Inventory.
- **Key contract:** `CatalogManagerInterface` — full read/write surface consumed by higher-level modules (e.g. Orders, Inventory)
- **Authorization:** Public read endpoints require no auth. Write endpoints and the admin product view require `auth:sanctum`; authorization is permission-based (`catalog.category.*`, `catalog.product.*`, `catalog.variant.*`) enforced via Laravel policies — any user granted a specific permission can act, independent of role.
- **Pagination:** List endpoints return `LengthAwarePaginator` with a `{data, links, meta}` envelope. `per_page` (1–100) and `page` are documented automatically by Scramble.
- **Product sort:** listing endpoints accept `?sort=cheapest|most_expensive|most_sold`. Price sorts use the default variant's `base_price`; `most_sold` uses a denormalized `sales_count` kept in sync hourly from realized orders (Order module's `orders:sync-sales-counts` → `CatalogManagerInterface::syncSalesCounts()`). Invalid values → 422.
- **Image input:** Write endpoints accept images two ways — either send a `media_id` (pre-uploaded via the Media endpoint) or attach the file inline as `multipart/form-data`. The two are mutually exclusive per field. Inline fields: `image` on category create, `primary_image` + `gallery[]` (index sets sort order) on product create/update, and `variant_image` on variant create/update. These multipart fields are documented in Scramble via `#[BodyParameter]` attributes.

### Inventory (Complete)
Tracks physical and reserved stock per SKU with an append-only audit ledger and full reservation lifecycle support.

- **Key entities:** `InventoryStock` (sku, quantity, reserved_quantity), `InventoryLedgerEntry` (immutable audit rows — no `updated_at`)
- **Key contract:** `InventoryManagerInterface` — `getStockBySku`, `getBatchStockBySkus`, `adjustStock`, `reserveStock`, `commitReservation`, `releaseReservation`. Available stock = `quantity − reserved_quantity`.
- **Concurrency safety:** All mutations use `DB::transaction()` + `lockForUpdate()` to eliminate oversell races.
- **Authorization:** Public stock reads require no auth. `POST /adjust` and `GET /sku/{sku}/ledger` require `auth:sanctum` + `inventory.stock.manage` / `inventory.ledger.view` respectively.

### Cart (Complete)
Provides guest and authenticated shopping carts with real-time stock validation and Catalog-enriched item pricing.

- **Key entities:** `Cart` (user_id or session_id keyed), `CartItem` (cart_id, sku, quantity — unique per cart)
- **Key contract:** `CartManagerInterface` — `findOrCreateCart`, `getCart`, `addItem`, `removeItem`, `updateQuantity`, `clearCart`.
- **Session identity:** Authenticated users are identified by `user_id`; guests use an `X-Session-Id` request header. If the header is absent, the middleware auto-generates a UUID and echoes it back as `X-Cart-Session-Id` in the response.
- **Stock validation:** Every `addItem` / `updateQuantity` call checks live inventory via `InventoryManagerInterface`. Insufficient stock or unknown SKU returns 422.
- **Catalog enrichment and limits:** `getCart()` batch-loads immutable variant DTOs. Add/update reject quantities above current stock or `max_quantity_per_order`; merge clamps to the lower bound. Cart items expose the configured/effective maximum, remaining addable quantity, and validity for stale carts.
- **Authorization:** No permissions required — cart operations are self-service.

### Order (Complete)
Immutable financial contract anchor. Converts a validated cart into a locked order, atomically reserves inventory, and enforces a 15-minute checkout TTL via a scheduled command.

- **Key entities:** `Order` (status, snapshotted `shipping_address`/`shipment_snapshot`/`customer_snapshot` JSON, integer totals), `OrderItem` (snapshotted sku, product_title, price_per_unit, line_total, `product_snapshot` JSON — all integers where monetary)
- **Key contract:** `OrderManagerInterface` — `createOrderFromCart`, `markAsPaid`, `markAsComplete`, `getUserOrders`, `findOrder`.
- **Checkout flow:** `POST /api/v1/orders` first aggregates cart quantities by SKU and batch-revalidates current Catalog limits. Only then does it replace a pending order, snapshot prices/rules/shipping, reserve Inventory, and hold Shipment capacity in one transaction. The cart remains until successful payment; quantity failure changes nothing.
- **TTL enforcement:** `orders:cancel-expired` runs every minute and cancels pending orders older than 15 minutes, releasing their inventory reservations.
- **Price snapshot:** Order items store prices at creation time and never change, even if the catalog is updated.
- **Customer & product snapshots:** Orders are historical records — later profile or catalog edits must never alter them. `customer_snapshot` (`name`, `last_name`, `phone`, `email`) is captured once via `IdentityManagerInterface::getUserSummary()` (no `User` model import into Order). `product_snapshot` (`title`, `sku`, `image_url`, `attributes`) is built entirely from the already-enriched `CartItemDTO` — no second Catalog call, no `Product`/`ProductVariant` model import into Order.
- **Shipment selection:** Checkout takes `shipment_method_code` (+ `address_id?`, `delivery_slot_id?`); the order stores an immutable `shipment_snapshot`. The legacy `shipment_method_id` column is retained (nullable) for backward compatibility but is no longer written.
- **Authorization:** Requires `auth:sanctum` + `order.create` permission. Customers receive this permission by default.

### Shipment (Complete)
Fulfillment lifecycle from checkout through payment to delivery, postal handoff, or store pickup. The four methods are fixed and **configuration-backed** (`config/shipment.php`) — there is no `shipment_methods` table.

- **Methods (by code):** `post_standard`, `post_express` (postal — end at `handed_to_post`, tracking number shown), `local_delivery` (dated slot required, capacity-limited), `in_person_pickup` (free, no address).
- **Key contract:** `ShipmentManagerInterface` — `getAvailableMethods`, `getAvailableDeliverySlots`, `validateSelection`, `holdForPendingOrder`, `releasePendingOrder`, `activateForPaidOrder`, `findForOrder`. Supported-region rule isolated behind `LocalDeliveryEligibilityInterface`.
- **Service area:** set `SHIPMENT_LOCAL_DELIVERY_CITY_IDS` (and/or `SHIPMENT_LOCAL_DELIVERY_PROVINCE_IDS`) to the region you deliver to yourself — comma-separated ids, matching on either list. Inside it, **both postal methods are withdrawn** (hidden in `GET /shipment/methods`, 422 at checkout) since the store delivers there directly; in-store pickup always stays available. Leave both empty to disable the restriction: local delivery is then offered everywhere and post is never withdrawn.
- **Lifecycle:** a held local-delivery slot is reserved at pending-order creation, confirmed when the order is **paid**, released on cancel/expiry. The operational `shipments` record is created only when the order becomes paid (idempotent by `order_id`, at the shared `markAsPaid` path, which also commits inventory once).
- **Status mapping:** shipment → order summary (`handed_to_post`/`out_for_delivery` → `shipped`; `delivered`/`picked_up` → `completed`). Postal tracking intentionally ends at `handed_to_post`.
- **Slots:** `shipment:generate-delivery-slots` generates dated cinema-session slots from recurring working periods (idempotent, scheduled daily). `POST /admin/shipment/delivery-slots/generate` triggers the identical run on demand — useful in local development, where no cron is running. Remaining capacity = capacity − admin-reserved − active reservations; overbooking prevented with row locks.
- **Default working hours** are seeded (Sat–Thu, 09:00–13:00 and 16:00–21:00, Friday closed) as *starting values only* — the admin owns the schedule from there via `/admin/shipment/delivery-working-periods`, and re-seeding never overwrites those edits.
- **Working-period admin API:** GET/POST /api/v1/admin/shipment/delivery-working-periods and PATCH/DELETE /api/v1/admin/shipment/delivery-working-periods/{id} manage recurring weekday templates through existing shipment.slot.view-admin / shipment.slot.manage permissions. Same-day periods cannot overlap; changes affect future generation and do not rewrite existing dated slots.
- **Endpoints:** customer `GET /shipment/methods`, `/shipment/delivery-slots`, `/shipments/{publicCode}`, `/orders/{order}/shipment`; admin `/admin/shipments*` (business-action POSTs) and `/admin/shipment/delivery-slots*`.
- **Authorization:** permission-based (`shipment.*`); customer gets `shipment.view-own`, admin gets all.

### Notification (Infrastructure ready — business events pending)
In-app notification storage plus multi-channel delivery. The module owns no business copy: the caller supplies the type, title, message, `data` payload, and channel list.

- **Channels:** `database` (in-app row) and `sms`. `NotificationChannelInterface` + `NotificationChannelFactory`; email and push are intentionally not implemented.
- **Key contract:** `NotificationManagerInterface` — `send(NotificationRequestDTO)`, `getUserNotifications`, `markAsRead`, `unreadCount`.
- **Tables:** `notifications` (`user_id`, `type`, `title`, `message`, JSON `data`, `read_at`) and `notification_deliveries` (external-attempt audit: `channel`, `status`, `provider`, `provider_reference`, `sent_at`, `failed_at`, `error`; `notification_id` is nullable because an SMS-only notification has no in-app row).
- **SMS is optional:** template names are the internal `NotificationTemplate` enum. If the active provider has no template id configured for one, the message is **skipped** — logged, recorded as a `skipped` delivery, never thrown and never counted as a failure. A recipient with no phone on file is skipped too.
- **Failure isolation:** a real SMS failure (provider down or rejecting) creates a *failed delivery record* — it never throws into the caller and never rolls back a payment or shipment.
- **Boundaries:** the recipient's phone comes from `IdentityManagerInterface::getUserSummary()`; SMS leaves only through `SmsManagerInterface`. No SMS provider is ever referenced here.
- **Endpoints:** `GET /api/v1/notifications`, `POST /api/v1/notifications/{id}/read` (`auth:sanctum`, `notification.view-own` / `notification.mark-read-own`; another user's notification returns 403).
- **Wired to the business flows via events.** Business modules dispatch primitives-only integration events; listeners in this module react. Nothing else calls `NotificationManagerInterface`.

| Event | Raised when | Customer | Admin |
|---|---|---|---|
| `OrderPaidEvent` | `markAsPaid` completes a real pending → paid transition | in-app + SMS | in-app |
| `PaymentFailedEvent` | server-side gateway verification rejects the payment | in-app | — |
| `OrderCancelledEvent` | customer or operator cancels an order | in-app + SMS | — |
| `ShipmentPreparingStartedEvent` | shipment enters `preparing` | SMS | — |
| `ShipmentSentEvent` | shipment reaches `handed_to_post` or `out_for_delivery` | in-app + SMS | — |
| `ShipmentDeliveredEvent` | shipment reaches `delivered` | in-app + SMS | — |

- **Transaction-safe:** every listener implements `ShouldHandleEventsAfterCommit`, so a rolled-back business transaction sends nothing. Repeat payment callbacks cannot duplicate notifications — the already-paid early return in `markAsPaid` means the event is never re-dispatched.
- **Silent by design:** the internal pending-order replacement during checkout, TTL order expiry, and in-store `picked_up`.

### Sms (Infrastructure ready)
Provider abstraction for notification SMS: provider selection, provider-specific formatting, nothing else.

- **Internal message format:** `SmsMessageDTO { receiver: "09121234567", template: "payment_success", parameters: { "OrderId": 123 } }`. Template names and parameter names are ours and never change per provider.
- **Providers:** `smsir` (translates to SMS.ir's `{mobile, templateId, parameters:[{name,value}]}` and `98…` numbering), `log` (dev default), `fake` (tests). Selected with `SMS_PROVIDER`; add a provider by implementing `SmsProviderInterface` and registering it in `SmsProviderFactory`.
- **Config:** `config/sms.php` — API key, endpoint, and per-template ids come from `SMS_SMSIR_*` env keys. No business parameter names in env. A template id left empty simply means that message is not sent.
- **Three outcomes:** `SmsResultDTO` is success, `skipped` (nothing attempted — not configured for that template), or failure (a real attempt that did not succeed).
- **Separate from OTP:** Identity's `OtpSenderInterface` is untouched. Same vendor, different responsibility.

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
| `GET` | `/catalog/products/{uuid}` | Single published product by its public UUID (with gallery + variants) |
| `GET` | `/catalog/products/slug/{slug}` | Product by URL slug |
| `GET` | `/catalog/variants/{variantId}` | Single variant by ID |
| `GET` | `/catalog/variants/sku/{sku}` | Variant by SKU |

### Catalog — Protected (`auth:sanctum` + catalog permission required)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/catalog/products/admin` | Paginated products in **any** status (admin index) — filters: `status`, `category_id`, `min_price`, `max_price`, `search` (title/description/slug/SKU), `sort` (`cheapest`/`most_expensive`/`most_sold`) |
| `GET` | `/catalog/products/{uuid}/admin` | Product by UUID regardless of publish status |
| `POST` | `/catalog/categories` | Create category |
| `PATCH` | `/catalog/categories/{id}` | Update category |
| `DELETE` | `/catalog/categories/{id}` | Delete category |
| `POST` | `/catalog/products` | Create product |
| `PATCH` | `/catalog/products/{uuid}` | Update product |
| `DELETE` | `/catalog/products/{uuid}` | Delete product |
| `POST` | `/catalog/products/{uuid}/variants` | Add variant to product |
| `PATCH` | `/catalog/variants/{variantId}` | Update variant |
| `DELETE` | `/catalog/variants/{variantId}` | Delete variant |

### Inventory — Public (no auth)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/inventory/sku/{sku}` | Single SKU stock summary |
| `POST` | `/inventory/batch` | Batch stock lookup by SKU array |

### Inventory — Protected (`auth:sanctum` + permission required)

| Method | Endpoint | Permission | Description |
|---|---|---|---|
| `POST` | `/inventory/adjust` | `inventory.stock.manage` | Adjust stock (restock, manual correction, etc.) |
| `GET` | `/inventory/sku/{sku}/ledger` | `inventory.ledger.view` | Paginated audit ledger for a SKU |

### Cart (guest or `auth:sanctum` — `cart.identify` middleware)

Send `X-Session-Id: <uuid>` for guest carts. Authenticated users use their Bearer token. If neither is present, a session UUID is auto-generated and returned as `X-Cart-Session-Id`.

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/cart` | Get current cart (with enriched item prices) |
| `POST` | `/cart/items` | Add item to cart (stock-validated) |
| `PATCH` | `/cart/items/{itemId}` | Update item quantity (stock-validated) |
| `DELETE` | `/cart/items/{itemId}` | Remove item from cart |
| `DELETE` | `/cart` | Clear entire cart |

### Orders (`auth:sanctum` + `order.create` required)

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/orders` | Place an order from the current cart |
| `GET` | `/orders` | List authenticated user's order history (paginated) |
| `POST` | `/orders/{order}/cancel` | Cancel your own pending order (releases stock) |

### Admin Orders (`auth:sanctum` + `order.view-admin` / `order.cancel-admin`)

View, search, and cancel only — order status transitions are owned by the Shipment module, so there is no status-change/create/edit endpoint here.

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/admin/orders` | Paginated all-orders list (filters: `status`, `order_id`, `user_id`, `date_from`, `date_to`); customer summary from `customer_snapshot` |
| `GET` | `/admin/orders/{order}` | Full order detail: customer/product snapshots, shipping, and live shipment status (via the Shipment contract) |
| `POST` | `/admin/orders/{order}/cancel` | Admin-cancel a pending order (releases inventory + slot hold; reuses the shared cancel primitive) |

Auto-generated interactive API docs are available at `/docs/api` when running locally (powered by Scramble).

---

### Notifications (`auth:sanctum` + `notification.*` required)

| Method | Endpoint | Permission | Description |
|---|---|---|---|
| `GET` | `/notifications` | `notification.view-own` | Caller's notifications, newest first, paginated |
| `POST` | `/notifications/{id}/read` | `notification.mark-read-own` | Mark own notification read (403 for another user's) |

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

## Scheduled Tasks & Cron

Several modules rely on Laravel's scheduler (defined in `routes/console.php`):

| Command | Frequency | Purpose |
|---|---|---|
| `orders:cancel-expired` | every minute | Cancel unpaid pending orders past the 15-min TTL and release their reservations |
| `payments:expire-stale` | every 5 minutes | Fail/settle stale payment attempts |
| `orders:sync-sales-counts` | hourly | Push best-seller tallies to Catalog |
| `shipment:generate-delivery-slots` | daily at 00:30 | Generate dated local-delivery sessions from the recurring working periods |

These fire **only if Laravel's scheduler is itself driven by the system cron**. In production add this **single** cron entry (it runs `schedule:run` every minute; Laravel then decides which commands are due):

```cron
* * * * * cd /path/to/Shop_api && php artisan schedule:run >> /dev/null 2>&1
```

That one line is all that's needed — do **not** add a separate cron entry per command. Delivery-slot generation is idempotent and safe to run daily: it never duplicates existing slots, overwrites operator-modified slots, or reopens administratively closed slots, so a missed run simply catches up on the next tick.

**Generate slots manually / on demand** (e.g. first deploy, or to widen the horizon):

```bash
php artisan shipment:generate-delivery-slots            # uses config('shipment.delivery.generation_days'), default 30
php artisan shipment:generate-delivery-slots --days=60  # generate 60 days ahead

# Same generation over HTTP, for environments with no cron (admin token + shipment.slot.manage):
#   POST /api/v1/admin/shipment/delivery-slots/generate   {"days": 30}   // days optional, 1-90
```

Before slots can be generated you must seed the recurring templates in `delivery_working_periods` (weekday `0`=Sun … `6`=Sat, plus `starts_at`/`ends_at`), and optionally `delivery_schedule_exceptions` (`closed` / `custom_hours`) for holidays or special hours. Slot duration, capacity, booking horizon, and lead time are all tunable in `config/shipment.php` (env-backed).

**Local development** (Windows or no cron): run the scheduler in the foreground instead of a cron entry:

```bash
php artisan schedule:work
```

or invoke the generator directly with the command above.

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
    │   ├── AuthControllerTest.php       # OTP request/verify, token issuance, single-use replay
    │   └── RolePermissionTest.php       # Role and permission assignment
    ├── Media/
    │   ├── MediaManagerTest.php         # MediaManagerInterface contract tests
    │   └── MediaUploadTest.php          # Upload/delete endpoints + auth boundaries
    ├── Inventory/
    │   ├── InventoryTest.php            # Stock CRUD, reservation lifecycle, batch lookup
    │   └── InventoryAuthorizationTest.php # Auth matrix: 401 / 403 / public access
    ├── Cart/
    │   └── CartTest.php                 # Guest + auth carts, stock validation, isolation
    └── Order/
        ├── OrderTest.php                # Checkout flow, customer/product snapshots + immutability, auto-cancel, TTL expiry, auth matrix
        └── AdminOrderTest.php           # Admin list/detail/cancel, permission matrix, no status-mutation endpoints
```

---

## Module Roadmap

| Module | Status |
|---|---|
| Identity | Complete |
| Media | Complete |
| Catalog | Complete |
| Inventory | Complete |
| Cart | Complete |
| Order | Complete |
| Payment | Planned |

---

## License

MIT
