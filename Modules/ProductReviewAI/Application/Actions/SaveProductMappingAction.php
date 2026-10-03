<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Actions;

use Modules\ProductReviewAI\Application\Support\SourceResolver;
use Modules\ProductReviewAI\Domain\Models\ExternalProductMapping;

/**
 * Persists the admin-confirmed internal↔external product mapping (upsert by
 * product + source).
 */
class SaveProductMappingAction
{
    public function __construct(
        private readonly SourceResolver $sources,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        int $productId,
        string $sourceCode,
        string $externalId,
        ?string $externalUrl,
        array $metadata = [],
    ): ExternalProductMapping {
        [$source] = $this->sources->resolve($sourceCode);

        /** @var ExternalProductMapping $mapping */
        $mapping = ExternalProductMapping::query()->updateOrCreate(
            ['product_id' => $productId, 'source_id' => $source->id],
            [
                'external_id' => $externalId,
                'external_url' => $externalUrl,
                'metadata' => $metadata === [] ? null : $metadata,
            ],
        );

        $mapping->setRelation('source', $source);

        return $mapping;
    }
}
