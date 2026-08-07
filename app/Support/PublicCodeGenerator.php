<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Exceptions\PublicCodeGenerationException;

/**
 * The one place that builds a customer-facing public code.
 *
 * Canonical shape: `<namespace><segment>-<SUFFIX>`, e.g. `bdo-Q8M2XC`. The
 * namespace and segment are lowercase; the six-character suffix is uppercase and
 * drawn from a human-safe alphabet with `0`, `O`, `1`, `I` and `L` removed so a
 * code read aloud to support, or copied off a printed invoice, cannot be
 * mistyped into a different record.
 *
 * The suffix is pure CSPRNG output. It is deliberately **not** derived from the
 * primary key, a hash, a timestamp, or a sequence — a customer-facing code must
 * leak neither record counts nor creation order.
 *
 * This class is intentionally free of persistence: it performs no queries and
 * imports no business-module model. Uniqueness belongs to the owning module,
 * which supplies its own existence check to {@see generateUnique()} — see
 * {@see HasPublicCode} for the shared model-side wiring.
 */
final class PublicCodeGenerator
{
    /**
     * Human-safe uppercase alphabet: no 0/O, no 1/I/L. Fixed contract, not
     * configuration — widening it later would make already-issued codes
     * ambiguous against newly issued ones.
     */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const SUFFIX_LENGTH = 6;

    public const DEFAULT_MAX_ATTEMPTS = 20;

    /** Namespaces are short lowercase letters, e.g. `bd`. */
    private const NAMESPACE_PATTERN = '/^[a-z]{1,8}$/';

    /**
     * Build one candidate code. Never touches the database, so a caller that
     * needs uniqueness must check it — use {@see generateUnique()}.
     *
     * @throws PublicCodeGenerationException when the namespace or segment is invalid
     */
    public static function generate(PublicCodeEntity|string $entity): string
    {
        return self::prefix($entity).self::suffix();
    }

    /**
     * Build a candidate, ask the owning module whether it already exists, and
     * retry until one is free.
     *
     * `$exists` is supplied by the caller precisely so this class never learns
     * which table or column backs a given entity — Product checks
     * `products.uuid`, Order checks `orders.public_code`, and neither ever sees
     * the other's table.
     *
     * @param  callable(string): bool  $exists  true when the candidate is already taken
     *
     * @throws PublicCodeGenerationException when every attempt collided
     */
    public static function generateUnique(
        PublicCodeEntity|string $entity,
        callable $exists,
        ?int $maxAttempts = null,
    ): string {
        $maxAttempts ??= self::maxAttempts();

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $candidate = self::generate($entity);

            if (! $exists($candidate)) {
                return $candidate;
            }
        }

        throw new PublicCodeGenerationException(sprintf(
            'Could not generate a free public code for "%s" after %d attempts.',
            self::prefix($entity),
            $maxAttempts,
        ));
    }

    /**
     * The full lowercase prefix including the trailing hyphen, e.g. `bdo-`.
     *
     * @throws PublicCodeGenerationException
     */
    public static function prefix(PublicCodeEntity|string $entity): string
    {
        return self::namespaceValue().self::segment($entity).'-';
    }

    /**
     * The configured namespace, normalized to lowercase.
     *
     * Required: a missing or malformed value is a deployment fault and fails
     * loudly rather than silently minting codes under a wrong prefix.
     *
     * @throws PublicCodeGenerationException
     */
    public static function namespaceValue(): string
    {
        $configured = config('public_codes.namespace');

        if (! is_string($configured)) {
            throw new PublicCodeGenerationException(
                'PUBLIC_CODE_NAMESPACE is not configured. Set it in .env (e.g. PUBLIC_CODE_NAMESPACE=bd).'
            );
        }

        $namespace = strtolower(trim($configured));

        if (preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1) {
            throw new PublicCodeGenerationException(sprintf(
                'PUBLIC_CODE_NAMESPACE "%s" is invalid: expected 1-8 lowercase letters (e.g. "bd").',
                $configured,
            ));
        }

        return $namespace;
    }

    /**
     * Does `$value` look like a code for this entity, ignoring input casing?
     *
     * Used to route a search term to an exact indexed lookup instead of a LIKE
     * scan. Matching is whole-string only — there is no partial public-code match.
     */
    public static function matches(?string $value, PublicCodeEntity|string $entity): bool
    {
        if ($value === null) {
            return false;
        }

        $pattern = '/^'.preg_quote(self::prefix($entity), '/').'['.self::ALPHABET.']{'.self::SUFFIX_LENGTH.'}$/';

        return preg_match($pattern, self::normalize($value)) === 1;
    }

    /**
     * Fold user input toward the canonical stored form: trimmed, lowercase
     * prefix, uppercase suffix. `  BDO-q8m2xc ` becomes `bdo-Q8M2XC`.
     *
     * Anything not shaped like a public code is returned trimmed and otherwise
     * untouched, so ordinary free-text search terms pass through unharmed.
     */
    public static function normalize(string $value): string
    {
        $trimmed = trim($value);

        if (preg_match('/^([A-Za-z]{1,16})-([A-Za-z0-9]{'.self::SUFFIX_LENGTH.'})$/', $trimmed, $matches) !== 1) {
            return $trimmed;
        }

        return strtolower($matches[1]).'-'.strtoupper($matches[2]);
    }

    /**
     * A route-constraint fragment accepting this entity's codes, optionally
     * alternated with a legacy pattern so identifiers issued before this scheme
     * keep resolving. The suffix accepts either case: a customer retyping a code
     * in lowercase should reach the controller and get a clean 404 at worst,
     * never a routing 404.
     */
    public static function routePattern(PublicCodeEntity|string $entity, ?string $legacyPattern = null): string
    {
        $own = preg_quote(self::prefix($entity), '/').'[A-Za-z0-9]{'.self::SUFFIX_LENGTH.'}';

        return $legacyPattern === null ? $own : '('.$legacyPattern.'|'.$own.')';
    }

    /**
     * @throws PublicCodeGenerationException
     */
    private static function segment(PublicCodeEntity|string $entity): string
    {
        if ($entity instanceof PublicCodeEntity) {
            return $entity->value;
        }

        $resolved = PublicCodeEntity::tryFrom(strtolower(trim($entity)));

        if ($resolved === null) {
            throw new PublicCodeGenerationException(sprintf(
                'Unknown public-code entity segment "%s". Add it to %s first.',
                $entity,
                PublicCodeEntity::class,
            ));
        }

        return $resolved->value;
    }

    /** Six characters of CSPRNG output over the approved alphabet. */
    private static function suffix(): string
    {
        $alphabet = self::ALPHABET;
        $lastIndex = strlen($alphabet) - 1;
        $suffix = '';

        for ($i = 0; $i < self::SUFFIX_LENGTH; $i++) {
            $suffix .= $alphabet[random_int(0, $lastIndex)];
        }

        return $suffix;
    }

    private static function maxAttempts(): int
    {
        $configured = (int) config('public_codes.max_attempts', self::DEFAULT_MAX_ATTEMPTS);

        return $configured > 0 ? $configured : self::DEFAULT_MAX_ATTEMPTS;
    }
}
