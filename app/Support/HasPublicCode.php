<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Exceptions\PublicCodeGenerationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Model-side wiring for a server-owned public code.
 *
 * Each consuming model declares its own entity segment and its own column, and
 * the uniqueness check runs against that model's table and nothing else — no
 * module ever queries another module's table to mint an identifier. Two entities
 * may legitimately share a suffix (`bdp-K92XMQ` and `bdo-K92XMQ`) because their
 * full prefixes differ.
 *
 * Two layers protect uniqueness:
 *   1. {@see generateUniquePublicCode()} rejects candidates already in the table.
 *   2. The column's unique index is the real authority; {@see createWithPublicCode()}
 *      treats a violation naming that column as a lost race and retries with a
 *      fresh candidate. Any other database error is rethrown untouched.
 *
 * The `creating` hook covers every normal application path. It is muted wherever
 * model events are disabled — seeders run that way — so code there must come
 * from {@see createWithPublicCode()}, which assigns it itself, or from an
 * explicit {@see generateUniquePublicCode()} call.
 */
trait HasPublicCode
{
    /** The entity this model's codes belong to. */
    abstract public static function publicCodeEntity(): PublicCodeEntity;

    /**
     * The column holding the code. Overridden by models that reuse an existing
     * column instead of adding a duplicate one (Product → `uuid`,
     * ProductVariant → `sku`).
     */
    public static function publicCodeColumn(): string
    {
        return 'public_code';
    }

    /** Auto-assign on create. Booted by Eloquent's boot{TraitName} convention. */
    protected static function bootHasPublicCode(): void
    {
        static::creating(function (Model $model): void {
            $column = static::publicCodeColumn();

            if (blank($model->getAttribute($column))) {
                $model->setAttribute($column, static::generateUniquePublicCode());
            }
        });
    }

    /**
     * A code that is free in this model's own table.
     *
     * @throws PublicCodeGenerationException
     */
    public static function generateUniquePublicCode(): string
    {
        $column = static::publicCodeColumn();

        return PublicCodeGenerator::generateUnique(
            static::publicCodeEntity(),
            static fn (string $candidate): bool => static::query()->where($column, $candidate)->exists(),
        );
    }

    /**
     * Create the record with a guaranteed code, retrying with a fresh candidate
     * if the database rejects it.
     *
     * The code is assigned here rather than left to the `creating` hook, because
     * that hook is muted wherever model events are disabled — seeders run that
     * way. Assignment goes through setAttribute, so it works whether or not the
     * column is mass-assignable (most are deliberately not).
     *
     * The retry closes the window the application-level check cannot: two
     * concurrent requests can both find a candidate free and only one can insert
     * it. Only a unique violation mentioning this model's code column is retried
     * — every other QueryException propagates unchanged, so genuine schema or
     * constraint failures are never swallowed.
     */
    public static function createWithPublicCode(array $attributes): static
    {
        $column = static::publicCodeColumn();
        $maxAttempts = max(1, (int) config('public_codes.max_attempts', PublicCodeGenerator::DEFAULT_MAX_ATTEMPTS));

        for ($attempt = 1; ; $attempt++) {
            try {
                $model = static::query()->newModelInstance($attributes);

                if (blank($model->getAttribute($column))) {
                    $model->setAttribute($column, static::generateUniquePublicCode());
                }

                $model->save();

                return $model;
            } catch (QueryException $e) {
                if ($attempt >= $maxAttempts || ! static::isPublicCodeCollision($e, $column)) {
                    throw $e;
                }

                // Drop any caller-supplied value so the next attempt generates a
                // genuinely new candidate instead of retrying the losing one.
                unset($attributes[$column]);
            }
        }
    }

    /**
     * Is this a unique-constraint violation on the public-code column?
     *
     * Deliberately narrow: it demands both a unique-violation signal and the
     * column name, so a duplicate slug, a duplicate transaction reference, or a
     * foreign-key failure is never mistaken for a code collision.
     */
    protected static function isPublicCodeCollision(QueryException $e, string $column): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());

        // 23000 (MySQL/SQLite integrity constraint) / 23505 (Postgres unique violation).
        if (! in_array($sqlState, ['23000', '23505'], true)) {
            return false;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, strtolower($column))
            && (str_contains($message, 'unique') || str_contains($message, 'duplicate'));
    }
}
