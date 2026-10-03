<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Exceptions;

use RuntimeException;

/**
 * A live external marketplace call failed (network error, non-2xx, or an
 * unparseable payload). Distinct from IntegrationUnavailableException, which is
 * a configuration problem rather than a request failure.
 */
class ExternalSourceException extends RuntimeException {}
