<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The single mapping between a business entity and its one-character public-code
 * segment. Nothing else in the repository may hard-code a full prefix such as
 * `bdp` or `bdo` — the namespace comes from configuration and the segment from
 * here, and only PublicCodeGenerator joins the two.
 *
 * Segments are historical contract: once a code is stored, its segment can never
 * change. `t` (not `p`) is the Payment segment because `p` belongs to Product.
 */
enum PublicCodeEntity: string
{
    case Product = 'p';
    case ProductVariant = 'v';
    case Order = 'o';
    case Payment = 't';
    case Shipment = 's';
    case Address = 'a';
    case Category = 'c';
    case Review = 'r';
    // `k` (not `t`, which belongs to Payment) — the customer-facing support
    // ticket handle, e.g. `bdk-Q8M2XC`.
    case Ticket = 'k';
    // Blog article handle, e.g. `bdb-K92XMQ`.
    case BlogPost = 'b';
    // Blog discussion comment, e.g. `bdm-K92XMQ` — `m` for "message", because
    // `c` already belongs to the Catalog category.
    case BlogComment = 'm';
}
