<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Contracts;

use Modules\ProductReviewAI\Domain\DTOs\ExternalProductDTO;
use Modules\ProductReviewAI\Domain\DTOs\ExternalReviewDTO;

/**
 * A pluggable external marketplace adapter (Digikala today; Torob/Amazon/… next).
 *
 * Adapters are addressed by a stable `code()` and resolved through
 * ReviewSourceFactory — business logic never branches on `if source == digikala`.
 * Every adapter must fail loudly (IntegrationUnavailableException on missing
 * configuration, ExternalSourceException on a failed call) rather than return
 * fabricated data.
 */
interface ReviewSourceInterface
{
    /** Stable driver code, e.g. `digikala`. */
    public function code(): string;

    /**
     * Search the marketplace for products matching a free-text query.
     *
     * @return list<ExternalProductDTO>
     */
    public function searchProducts(string $query, int $limit = 5): array;

    /**
     * Fetch a single external product by its marketplace id.
     */
    public function getProduct(string $externalId): ExternalProductDTO;

    /**
     * Collect up to `$limit` customer reviews for an external product.
     *
     * @return list<ExternalReviewDTO>
     */
    public function getReviews(string $externalId, int $limit): array;
}
