<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Exceptions;

use RuntimeException;

/**
 * Internal signal: a conditional lifecycle write matched no row because this
 * worker no longer owns the run (it was reconciled as abandoned, or another
 * delivery holds it). The pipeline stops without writing anything further —
 * in particular a late worker can never turn a terminal `failed` into
 * `completed`. Never rendered over HTTP.
 */
final class GenerationOwnershipLostException extends RuntimeException {}
