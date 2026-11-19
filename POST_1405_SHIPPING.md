# Iran Post 1405 shipping — implementation notes

Status: implemented 2026-11-19. Owner: Shipment module. All money is **integer TOMANS**.

The engine is **fail-closed**. If a method can't be priced from approved data, it is
unavailable (`price: null`) in the method list and gets a 422 at checkout. There is no
static postal price and no fallback.

---

## 1. What exists now

| Piece | Location |
|---|---|
| 21 service definitions + 66 tariff rows (PDF rials ÷ 10) | `Modules/Shipment/Domain/Postal/Post1405Catalog.php` |
| Value objects / enums | `Domain/Postal/PostalServiceDefinition.php`, `PostalScope.php`, `PostalZone.php`, `PostalUnavailableReason.php` |
| Pricing engine | `Infrastructure/Shipping/Post/PostShippingCalculator.php` (bound to `ShippingCostCalculatorInterface`) |
| Tariff lookup (whole year cached per request; fails closed on ambiguity) | `Infrastructure/Persistence/Repositories/PostalTariff1405Repository.php` |
| Integrity check | `Infrastructure/Persistence/Post1405CatalogVerifier.php` + `php artisan shipping:post-1405:verify` |
| Tables | `postal_services_1405`, `postal_tariffs_1405`, `shipments.price_breakdown` (all additive) |
| Seeders | `ShippingParameterSeeder` (updated), `PostalService1405Seeder`, `PostalTariff1405Seeder`, `PostTariffSeeder` (now a no-op) |
| Config | `config/shipping.php` (`post_1405.*`, `surcharges.*`), `config/shipment.php` (legacy codes retired) |
| Contract changes | `ShipmentManagerInterface::getAvailableMethods(int, ?int, ?ShipmentParcelDTO)`, `quoteSelection(ShipmentSelectionDTO, ShipmentParcelDTO): ShippingQuoteDTO` (replaces `resolveShippingCostForSelection`), `IdentityManagerInterface::getLocationReferences()` |

### The 21 methods and their defaults

| Scope | Codes (default **on** in bold) |
|---|---|
| Non-priority | `post_economy_unregistered`, `post_registered_document`, `post_registered_packet`, **`post_registered_parcel`** |
| Specialised (derived — never quotable) | `post_mbag_unregistered`, `post_mbag_registered`, `post_periodical` |
| Urban priority | `post_urban_{3h,sameday,nextday}_envelope`, **`post_urban_{3h,sameday,nextday}_parcel`** |
| Intercity priority | `post_intercity_{sameday,d1,d2,d3}_envelope`, **`post_intercity_{sameday,d1,d2,d3}_parcel`** |

Turning a toggle on only lets the method be considered. It's offered only when **all** of these hold:

1. It is enabled.
2. Its content kind matches the shop's (`goods`). Documents and envelopes never quote for goods.
3. The weight is inside its limits and a closed tariff bracket matches.
4. The origin (19/599) and the destination province **and** city are known.
5. The route is covered:
   - non-priority services and D+3 are open everywhere;
   - D+1, D+2 and intercity same-day need an allow-list;
   - urban services need the destination to be the origin city, a city coverage list, and an explicit urban zone;
   - intercity same-day also needs a known carton size ≤ 6 and a weight ≤ 10 kg.
6. The address is not inside the local-delivery area.
7. Every `shipping_parameters` row the quote needs exists.

### Formula (per method)

```
service_charge     = base_tariff + extra_started_kg × extra_rate        (above extra_above_grams)
delivery_surcharge = 20 % (province 8 / 5)  XOR  15 % (metro city, SHIPPING_METROPOLITAN_ENABLED)
large_province     = 5 % if province ∈ {21,16,17,10,11,4,15,31}  (SHIPPING_LARGE_PROVINCE_ENABLED)
island             = started_kg(total weight) × 25,500    (only explicitly listed destinations)
fragile            = 25 % if SHIPPING_DEFAULT_PACKAGE_TYPE=fragile
electronic_service = 7,700 for registered non-priority (document / packet / parcel)
compensation       = 0 (disabled until the business confirms the real fee)
postal_tax         = floor( Σ postal_tax_components × 10 / 100 )
packaging          = 50,000 once per postal order (untaxed, never per item / kg)
TOTAL              = sum of all the above
```

- Every percentage is taken from the **postal service charge**. It's never taken from the island fee, the packaging fee, or another surcharge.
- Each percentage is floored to a whole toman before the components are added up.
- The components that are taxed come from `config('shipping.post_1405.postal_tax_components')`, so you can exempt one without changing the 10 %.

**Postal tax is inside `shipping_cost`.** `orders.tax_amount` is product tax (still 0) and never gets postal tax. The customer pays postal tax exactly once.

---

## 2. Sample response — four parcel methods, four prices

`GET /api/v1/shipment/methods?address_id=…`

- Destination: Mashhad (city 347, province 11; non-neighbour of Qom).
- Cart: 2 × 650 g + 1 × 1,200 g = **2,500 g**.
- D+1/D+2 coverage configured for province 11.
- Captured from a real seeded database and trimmed to the available methods plus one unavailable one.

```json
{
  "data": [
    { "code": "post_registered_parcel", "title": "امانت پستی ثبتی", "type": "postal", "price": 117738,
      "available": true, "unavailable_reason": null, "unavailable_code": null, "currency": "TOMAN",
      "tariff_year": 1405, "scope": "non_priority", "package_kind": "parcel", "weight_grams": 2500, "rate_zone": "non_neighbor",
      "price_breakdown": { "base_price": 44900, "extra_weight": 0, "delivery_surcharge": 6735, "large_province_surcharge": 2245,
        "island_surcharge": 0, "fragile_surcharge": 0, "electronic_service_charge": 7700, "compensation": 0,
        "postal_tax": 6158, "packaging": 50000, "total": 117738 } },
    { "code": "post_intercity_d1_parcel", "title": "ویژه D+1 — بسته", "price": 304760, "available": true,
      "price_breakdown": { "base_price": 153000, "extra_weight": 40000, "delivery_surcharge": 28950, "large_province_surcharge": 9650,
        "island_surcharge": 0, "fragile_surcharge": 0, "electronic_service_charge": 0, "compensation": 0,
        "postal_tax": 23160, "packaging": 50000, "total": 304760 } },
    { "code": "post_intercity_d2_parcel", "title": "اکسپرس D+2 — بسته", "price": 245360, "available": true,
      "price_breakdown": { "base_price": 110000, "extra_weight": 38000, "delivery_surcharge": 22200, "large_province_surcharge": 7400,
        "island_surcharge": 0, "fragile_surcharge": 0, "electronic_service_charge": 0, "compensation": 0,
        "postal_tax": 17760, "packaging": 50000, "total": 245360 } },
    { "code": "post_intercity_d3_parcel", "title": "پیشتاز D+3 — بسته", "price": 228200, "available": true,
      "price_breakdown": { "base_price": 100000, "extra_weight": 35000, "delivery_surcharge": 20250, "large_province_surcharge": 6750,
        "island_surcharge": 0, "fragile_surcharge": 0, "electronic_service_charge": 0, "compensation": 0,
        "postal_tax": 16200, "packaging": 50000, "total": 228200 } },
    { "code": "post_intercity_sameday_parcel", "title": "بین‌شهری همان‌روز — بسته", "price": null, "available": false,
      "unavailable_reason": "This postal service is not available for your address.", "unavailable_code": "route_not_supported",
      "rate_zone": "non_neighbor", "price_breakdown": null }
  ]
}
```

The spec example (Qom → Ahvaz, 910 g, D+3 parcel) prices at exactly **176,500**:
100,000 base + 15,000 (15 % metropolitan) + 11,500 tax + 50,000 packaging.
`Post1405ShippingMethodsTest` asserts this response field by field.

---

## 3. Legacy vs new

| Aspect | Before | Now |
|---|---|---|
| Postal methods | `post_standard`, `post_express` | 21 official 1405 codes; legacy codes retired (readable only) |
| Tariffs | 18 illustrative generic brackets in `post_tariffs`, half-open | 66 published rates in `postal_tariffs_1405`, closed gram brackets, explicit `extra_above_grams` |
| Envelope vs parcel | not distinguished | separate services; goods never priced as documents/envelopes |
| Missing weight | counted as 0 → static fallback | 422 `items.{sku}.weight_grams`; method unavailable in list |
| Missing tariff / origin / destination | silent static-price fallback | method unavailable / 422 — no fallback |
| Unknown destination | classified `non_neighbor` | rejected before pricing |
| Surcharge base | subtotal **including** island fee | postal service charge only |
| 15 % metropolitan | not implemented | approved 7-city list, switchable |
| 20 % Tehran/Alborz, 5 % large province | env lists (empty by default) | fixed verified province ids (Khuzestan excluded) |
| Island fee | 255,000 (a rial figure in a toman column) | 25,500 tomans/started kg, explicit destinations only |
| Postal tax | none | 10 %, inside `shipping_cost`, configurable components |
| Packaging | none | 50,000 once per postal order, untaxed |
| Route availability | every postal method everywhere | D+1/D+2/same-day/urban need explicit coverage |
| Price shown | one `price` | `price` + itemised `price_breakdown`, `rate_zone`, `weight_grams`, `unavailable_code` |
| Unavailable price | static config price | `null` |
| Shipment record | `shipping_cost` only | `shipping_cost` + `price_breakdown` |

---

## 4. Deployment plan (historical orders and tariffs preserved)

1. **Back up** `orders`, `shipments`, `post_tariffs` and `shipping_parameters`. No migration modifies these, but take the backup anyway.
2. **Set the product weights first.** Any variant with a null or zero `product_variants.weight_grams` blocks postal checkout for every cart that contains it. This query lists the gaps:
   ```sql
   SELECT sku FROM product_variants WHERE weight_grams IS NULL OR weight_grams <= 0;
   ```
3. **`.env`:** add the keys from `.env.example` → "POST SHIPPING — IRAN POST 1405". You need at least `SHIPPING_ORIGIN_PROVINCE_ID=19`, `SHIPPING_ORIGIN_CITY_ID=599`, `SHIPPING_PACKAGING_PRICE_TOMAN=50000` and `SHIPPING_POSTAL_TAX_PERCENT=10`. Then run `php artisan config:clear` (or `config:cache`).
   - These keys are no longer read: `SHIPMENT_POST_STANDARD_PRICE`, `SHIPMENT_POST_EXPRESS_PRICE`, `SHIPPING_LARGE_PROVINCE_IDS`, `SHIPPING_TEHRAN_ALBORZ_PROVINCE_IDS`.
4. **`php artisan migrate --force`.**
   - The 3 migrations are additive.
   - If `postal_services_1405` / `postal_tariffs_1405` already exist (hand-made SQL), the migrations only add missing columns.
   - The unique rate key is added only when it's absent **and** the data has no duplicates.
   - Nothing is dropped or truncated.
5. **Seed** — each seeder inserts missing rows only and never overwrites:
   ```bash
   php artisan db:seed --class="Modules\Shipment\Infrastructure\Persistence\Seeders\ShippingParameterSeeder" --force
   php artisan db:seed --class="Modules\Shipment\Infrastructure\Persistence\Seeders\PostalService1405Seeder" --force
   php artisan db:seed --class="Modules\Shipment\Infrastructure\Persistence\Seeders\PostalTariff1405Seeder" --force
   ```
   Read the warnings. Any "Not overwritten" or "Conflict" line is a production value that differs from the catalog, and an operator needs to decide on it. Production already has `metropolitan_percentage` and island `25500`, so nothing should change there.
6. **`php artisan shipping:post-1405:verify`** — it must exit 0. It checks 21 services, 66 tariffs, and 0 negative prices, overlaps, or unknown codes/zones/years. Every configured province/city id must exist with the expected name and province.
7. **Deploy the frontend changes (§6) at the same time.** The old `post_standard`/`post_express` codes return 422 as soon as the backend is live.
8. **Smoke test:**
   - `GET /shipment/methods` with an Ahvaz address and a 910 g cart: D+3 should show 176,500.
   - Place an order and check `orders.shipment_snapshot.price_breakdown`.
   - Pay it and check that `shipments.price_breakdown` is equal.

**Historical safety:**

- Paid orders, `orders.shipping_cost`, `orders.shipment_snapshot`, `shipments.shipping_cost` and payment rows are never read for repricing or rewritten.
- An already-**pending** legacy order (`post_standard`) can still be paid. Activation copies its frozen snapshot cost unchanged; this is covered by a test.
- Legacy `post_tariffs` rows are left as they are.

**Rollback:** redeploy the previous release. The new tables and columns are additive and harmless to old code, so they can stay. Don't drop them while any order snapshot references a 1405 method.

---

## 5. Open questions for the business / Iran Post (not invented — currently fail-closed)

1. **Same-province 3–5 kg registered parcel = 27,000 tomans.** That's lower than the 2–3 kg rate of 32,000. It's reproduced verbatim and flagged by the verifier. Please confirm it with Iran Post.
2. **Registered parcel starts above 2 kg.** Under 2 kg, goods currently have only priority services. Can ordinary goods use `post_registered_packet` (پاکت جوف/بسته, 0.5–2 kg)? If yes, enable `SHIPPING_ENABLE_POST_REGISTERED_PACKET`. What service ships goods **≤ 500 g** at non-priority rates? None is defined for goods today.
3. **D+1 / D+2 coverage from Qom.** Which destination provinces or cities are served? Fill in `SHIPPING_POST_INTERCITY_D1_*` / `_D2_*`. Both stay unavailable until then.
4. **Intercity same-day.**
   - Which routes from Qom have the required infrastructure?
   - What carton number does the shop actually ship in (`SHIPPING_POST_CARTON_SIZE`)?
   - Are there other acceptance conditions?
5. **Urban services in Qom.** Is 3-hour, same-day or next-day available in Qom city, and is Qom a `metropolis`, a `province_center`, or something else for Table 3?
   - Today this is moot: Qom city is inside the local-delivery area, so all postal methods are withdrawn there.
   - Should postal (urban) be offered inside the local area as well? That needs a business-rule change.
6. **Is D+3 (Pishtaz) truly nationwide from Qom?** It's currently treated as open on every intercity route.
7. **Can intercity services serve another address inside Qom city?** They're currently refused there (same city = urban).
8. **15 % metropolitan list.** It's the shop's *proposed* list: Mashhad, Isfahan, Shiraz, Tabriz, Qom, Ahvaz, Kermanshah. Please confirm with Iran Post — should Karaj or others be added?
9. **Island destinations** (Kish, Qeshm, …): which city ids should get the 25,500/kg fee? The lists are empty today.
10. **Compulsory compensation** (Table 7 ceilings: 6,000 / 10,000): is it actually charged, and how much?
11. **Electronic id + SMS (7,700)** is applied to registered non-priority items. Confirm that applies to the parcel rows above 2 kg too (Table 1 rows 3–7).
12. **Postal-tax base.** It currently includes every postal component except packaging. Confirm that the electronic-service fee and the island fee are taxable.
13. **Fragile.** There's no per-product flag yet, only a store-wide switch. Do any stationery products need it (liquids: ink, glue)?
14. **M-bag and periodicals.** Which base service does the proportional / 50 % calculation reference? Do we ever ship printed matter as such?
15. **Contracted prices.** These figures are published *ceilings*. If the shop has a contract with lower rates, edit the `postal_tariffs_1405` rows. Seeders and the verifier report the edits and never revert them.

---

## 6. Frontend migration guide

### 6.1 `GET /api/v1/shipment/methods?address_id={id}` (changed, same URL, same auth)

**Removed codes**
- `post_standard` and `post_express` are never returned any more, and checking out with them is a **422**.
- Remove any hard-coded references to them. Old orders may still show them, so keep their labels for order history (§6.4).

**New postal codes** — with the default config these 8 can appear. Render whatever the API returns; don't hard-code the list.

```
post_registered_parcel, post_intercity_d3_parcel, post_intercity_d2_parcel, post_intercity_d1_parcel,
post_intercity_sameday_parcel, post_urban_3h_parcel, post_urban_sameday_parcel, post_urban_nextday_parcel
```

**Each method now also has** (existing fields unchanged):

| Field | Type | Notes |
|---|---|---|
| `price` | `int \| null` | ⚠️ **can now be `null`** — for an unavailable postal method. It's never 0 for postal. For local delivery and pickup it's always an int. |
| `unavailable_code` | `string \| null` | Machine-readable. Use it for UI logic, and `unavailable_reason` (English) for the text. |
| `currency` | `"TOMAN"` | On every method. |
| `tariff_year` | `int \| null` | `1405` for postal. |
| `scope` | `string \| null` | `non_priority` / `urban` / `intercity`. |
| `package_kind` | `string \| null` | `parcel` (or `envelope`, …). |
| `weight_grams` | `int \| null` | The cart weight that was priced. |
| `rate_zone` | `string \| null` | `same_province` / `neighbor` / `non_neighbor` / `metropolis` / `province_center` / `other_city`. |
| `price_breakdown` | `object \| null` | Itemised tomans (see §2). `total === price`. `null` when unavailable and for local delivery / pickup. |
| `missing_weight_skus` | `string[]` | Only present when `unavailable_code === "weight_missing"`. |

`estimated_min_days` / `estimated_max_days` are `null` for the new postal methods. Show the service label from `title` (e.g. "D+3") instead.

**What to change in the UI**
1. List only methods with `available === true` as selectable. Optionally show the others greyed out with `unavailable_reason`.
2. **Never format `price` without a null check.** `null` means "not available" — it doesn't mean free.
3. Show each postal method's own price; they differ. You can show `price_breakdown`: base, surcharges, «مالیات پستی ۱۰٪», «بسته‌بندی ۵۰٬۰۰۰ تومان».
4. **Prices depend on the cart and the address.** Re-fetch the methods after any cart change (quantity, add, remove) and after any address change.
5. `unavailable_code` values to handle:

   | Code | Meaning / suggested UX |
   |---|---|
   | `weight_missing` | An item has no shipping weight. Postal is blocked. Show "contact support" or let the customer choose pickup / local delivery. `missing_weight_skus` lists the SKUs. |
   | `cart_empty` | No items in the cart. |
   | `destination_incomplete` | The address has no province or city. Ask the customer to edit the address. |
   | `inside_local_delivery_area` | Suggest local delivery or pickup instead. |
   | `route_not_supported`, `zone_unclassified`, `tariff_missing`, `weight_not_supported`, `carton_size_unknown`, `carton_too_large`, `content_not_eligible` | Not offered for this cart or address. Hide the method or grey it out. |
   | `origin_not_configured`, `pricing_parameter_missing`, `service_disabled`, `derived_tariff_unresolved`, `unknown_service` | Server-side configuration issue. Hide the method. |

6. If **no** postal method is available, the checkout must still offer local delivery or pickup when those are available.

### 6.2 `POST /api/v1/orders` (checkout)

- The request body is unchanged: `shipment_method_code`, `address_id`, `delivery_slot_id?`, `notes?`. Send one of the new codes.
- **Never send a price.** Anything like `shipping_cost` is ignored, because the server recalculates.
- New 422 shapes to handle:
  - `errors["items.{SKU}.weight_grams"]` — an item has no shipping weight (postal only).
  - `errors.shipment_method_code` — the method is no longer available: disabled, route unsupported, no tariff, a retired legacy code, or the address is inside the local area. **Re-fetch `/shipment/methods`** and ask the customer to choose again.
  - `errors.address_id` — the address has no province or city, or it's invalid.
- The response `shipping_cost` equals the `price` shown in the list for the same cart and address. It **already includes** the 10 % postal tax and the 50,000 packaging fee, so don't add them again. `tax_amount` stays 0.
- `total_amount` = items − discounts + `shipping_cost`. That's unchanged.

### 6.3 Payment

No change. The payable amount is still the order's `total_amount`, which already includes the full postage.

### 6.4 Order and shipment detail (read endpoints)

- `shipment_snapshot` on new orders adds `price_breakdown`, `tariff_year`, `rate_zone`, `weight_grams`, `currency` and `delivery_surcharge_rule`. Show the breakdown on order pages if you like.
- Shipment resources (`GET /api/v1/shipments/{code}`, the order detail `shipment`, admin shipments) add `price_breakdown`. It's `null` for old orders and for local delivery / pickup.
- **Old orders** still carry `method_code: "post_standard" | "post_express"` and their original `shipping_cost`, with no breakdown. Keep a label mapping for them («پست عادی» / «پست سفارشی»), and treat a missing `price_breakdown` as normal.

### 6.5 Persian labels

Use `title` from the API — it's already Persian for the postal methods (e.g. «پیشتاز D+3 — بسته», «امانت پستی ثبتی»). The local-delivery and pickup titles are unchanged.
