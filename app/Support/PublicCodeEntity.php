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
}
