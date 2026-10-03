<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\DTOs;

/**
 * One product candidate returned by an external marketplace search.
 */
class ExternalProductDTO
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $title,
        public readonly ?string $url,
    ) {}

    /**
     * @return array{external_id: string, title: string, url: string|null}
     */
    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'title' => $this->title,
            'url' => $this->url,
        ];
    }
}
