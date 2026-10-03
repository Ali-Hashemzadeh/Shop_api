<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Exceptions;

use RuntimeException;

/**
 * The AI provider returned an error, an empty completion, or output that could
 * not be parsed as the required JSON shape.
 */
class AiGenerationException extends RuntimeException {}
