<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a required external integration is not configured (e.g. a missing
 * AVALAI_API_KEY or an empty Digikala base URL).
 *
 * The module never falls back to fake data or silently degrades: the workflow
 * stops, the failure is written to the generation log, and the admin sees that
 * the integration is unavailable.
 */
class IntegrationUnavailableException extends RuntimeException {}
