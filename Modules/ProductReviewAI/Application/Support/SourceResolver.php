<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Support;

use Modules\ProductReviewAI\Domain\Contracts\ReviewSourceInterface;
use Modules\ProductReviewAI\Domain\Exceptions\SourceNotConfiguredException;
use Modules\ProductReviewAI\Domain\Models\ReviewSource;
use Modules\ProductReviewAI\Infrastructure\Support\ReviewSourceFactory;

/**
 * Resolves a source `code` to its persisted row + concrete adapter in one place.
 */
class SourceResolver
{
    public function __construct(
        private readonly ReviewSourceFactory $factory,
    ) {}

    /**
     * @return array{0: ReviewSource, 1: ReviewSourceInterface}
     */
    public function resolve(string $code): array
    {
        /** @var ReviewSource|null $source */
        $source = ReviewSource::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($source === null) {
            throw new SourceNotConfiguredException("Unknown or inactive review source: [{$code}].");
        }

        return [$source, $this->factory->make($source->driver)];
    }
}
