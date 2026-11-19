<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\DTOs;

/**
 * Immutable snapshot of exactly what a generation run was asked to do, stored
 * on `ai_review_generations.request_context` at request time.
 *
 * The pipeline executes from this snapshot (not from today's config/mapping/
 * prompt), and a retry copies it verbatim — so a retry reproduces the original
 * request instead of silently using the current product description, model,
 * prompt version, or external mapping. It holds no secrets and no personal data:
 * product title/description (catalog content), ids, codes and versions only.
 *
 * Historical runs created before this snapshot existed have none; fromArray()
 * returns null for them and their retry is refused (409) rather than guessed.
 */
final class GenerationRequestContext
{
    public const VERSION = 1;

    public function __construct(
        public readonly int $productId,
        public readonly ?string $productUuid,
        public readonly string $productTitle,
        public readonly ?string $productDescription,
        public readonly int $sourceId,
        public readonly string $sourceCode,
        public readonly int $mappingId,
        public readonly string $externalId,
        public readonly int $count,
        public readonly string $aiProvider,
        public readonly string $aiModel,
        public readonly ?string $analysisPromptName,
        public readonly ?int $analysisPromptVersion,
        public readonly ?string $generationPromptName,
        public readonly ?int $generationPromptVersion,
        public readonly int $reviewLimit,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if (! is_array($data) || (int) ($data['version'] ?? 0) !== self::VERSION) {
            return null;
        }

        $required = [
            $data['product']['id'] ?? null,
            $data['source']['id'] ?? null,
            $data['source']['code'] ?? null,
            $data['mapping']['id'] ?? null,
            $data['mapping']['external_id'] ?? null,
            $data['count'] ?? null,
            $data['ai']['provider'] ?? null,
            $data['ai']['model'] ?? null,
        ];

        if (in_array(null, $required, true)) {
            return null;
        }

        $version = static fn (mixed $v): ?int => is_numeric($v) ? (int) $v : null;

        return new self(
            productId: (int) $data['product']['id'],
            productUuid: isset($data['product']['uuid']) ? (string) $data['product']['uuid'] : null,
            productTitle: (string) ($data['product']['title'] ?? ''),
            productDescription: isset($data['product']['description']) ? (string) $data['product']['description'] : null,
            sourceId: (int) $data['source']['id'],
            sourceCode: (string) $data['source']['code'],
            mappingId: (int) $data['mapping']['id'],
            externalId: (string) $data['mapping']['external_id'],
            count: (int) $data['count'],
            aiProvider: (string) $data['ai']['provider'],
            aiModel: (string) $data['ai']['model'],
            analysisPromptName: $data['prompts']['analysis']['name'] ?? null,
            analysisPromptVersion: $version($data['prompts']['analysis']['version'] ?? null),
            generationPromptName: $data['prompts']['generation']['name'] ?? null,
            generationPromptVersion: $version($data['prompts']['generation']['version'] ?? null),
            reviewLimit: max(1, (int) ($data['collection']['review_limit'] ?? 100)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'product' => [
                'id' => $this->productId,
                'uuid' => $this->productUuid,
                'title' => $this->productTitle,
                'description' => $this->productDescription,
            ],
            'source' => ['id' => $this->sourceId, 'code' => $this->sourceCode],
            'mapping' => ['id' => $this->mappingId, 'external_id' => $this->externalId],
            'count' => $this->count,
            'ai' => ['provider' => $this->aiProvider, 'model' => $this->aiModel],
            'prompts' => [
                'analysis' => ['name' => $this->analysisPromptName, 'version' => $this->analysisPromptVersion],
                'generation' => ['name' => $this->generationPromptName, 'version' => $this->generationPromptVersion],
            ],
            'collection' => ['review_limit' => $this->reviewLimit],
        ];
    }

    /**
     * The product context handed to the AI prompts and carried in the job payload.
     *
     * @return array<string, mixed>
     */
    public function productContext(): array
    {
        return [
            'product_id' => $this->productId,
            'uuid' => $this->productUuid,
            'title' => $this->productTitle,
            'description' => $this->productDescription,
        ];
    }
}
