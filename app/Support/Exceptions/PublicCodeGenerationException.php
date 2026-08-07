<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * Raised when a public code cannot be produced: the configured namespace is
 * missing or malformed, the entity segment is unknown, or the bounded retry
 * budget was exhausted without finding a value free in the owning table.
 *
 * Exhaustion is astronomically unlikely (31^6 ≈ 887 million suffixes per
 * prefix), so hitting it means something is genuinely wrong — a broken
 * existence check or a saturated keyspace — and must surface loudly rather
 * than fall back to a weaker identifier.
 */
class PublicCodeGenerationException extends RuntimeException {}
