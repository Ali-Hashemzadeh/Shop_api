<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\DTOs;

use Carbon\Carbon;
use Modules\ProductReviewAI\Domain\Models\ExternalProductMapping;

/**
 * Immutable view of an internal-product ↔ external-product mapping.
 */
class ExternalProductMappingDTO
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public readonly int $id,
        public readonly int $productId,
        public readonly int $sourceId,
        public readonly ?string $sourceCode,
        public readonly string $externalId,
        public readonly ?string $externalUrl,
        public readonly ?array $metadata,
        public readonly Carbon $createdAt,
    ) {}

    public static function fromModel(ExternalProductMapping $m, ?string $sourceCode = null): self
    {
        return new self(
            id: (int) $m->id,
            productId: (int) $m->product_id,
            sourceId: (int) $m->source_id,
            sourceCode: $sourceCode ?? $m->source?->code,
            externalId: (string) $m->external_id,
            externalUrl: $m->external_url,
            metadata: $m->metadata,
            createdAt: Carbon::parse($m->created_at),
        );
    }
}
