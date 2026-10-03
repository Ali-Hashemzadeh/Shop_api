<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Actions;

use Modules\ProductReviewAI\Application\Support\SourceResolver;
use Modules\ProductReviewAI\Domain\DTOs\ExternalProductDTO;

/**
 * Searches an external source for products so an admin can confirm which one
 * maps to the internal product. Selection is never automatic.
 */
class SearchExternalProductsAction
{
    public function __construct(
        private readonly SourceResolver $sources,
    ) {}

    /**
     * @return list<ExternalProductDTO>
     */
    public function handle(string $sourceCode, string $query): array
    {
        [, $adapter] = $this->sources->resolve($sourceCode);

        return $adapter->searchProducts($query);
    }
}
