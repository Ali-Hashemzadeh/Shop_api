<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Support;

use Modules\ProductReviewAI\Domain\Contracts\ReviewSourceInterface;
use Modules\ProductReviewAI\Domain\Exceptions\SourceNotConfiguredException;
use Modules\ProductReviewAI\Infrastructure\Sources\DigikalaSource;

/**
 * Resolves a source driver code to its concrete adapter. This is the ONLY place
 * a driver code maps to a class, so business logic never branches on the source
 * (`if source == digikala` is forbidden). New marketplaces register here.
 */
class ReviewSourceFactory
{
    /** @var array<string, class-string<ReviewSourceInterface>> */
    private array $drivers = [
        'digikala' => DigikalaSource::class,
    ];

    public function make(string $driver): ReviewSourceInterface
    {
        if (! isset($this->drivers[$driver])) {
            throw new SourceNotConfiguredException("Unknown review source driver: [{$driver}].");
        }

        // Resolved through the container so tests can swap the concrete adapter
        // for a test double implementing ReviewSourceInterface.
        return app($this->drivers[$driver]);
    }

    /**
     * @param  class-string<ReviewSourceInterface>  $driverClass
     */
    public function register(string $driver, string $driverClass): void
    {
        $this->drivers[$driver] = $driverClass;
    }
}
