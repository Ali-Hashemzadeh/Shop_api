<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Exceptions\PublicCodeGenerationException;
use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Tests\TestCase;

/**
 * The shared generator in isolation: format, alphabet, namespace handling, and
 * the bounded uniqueness retry. No database is touched here — that is exactly
 * the contract this class is meant to keep.
 */
class PublicCodeGeneratorTest extends TestCase
{
    private const CANONICAL = '/^bd[a-z]-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/';

    /** @test */
    public function every_entity_produces_its_documented_prefix_and_a_six_character_suffix(): void
    {
        $expected = [
            'p' => PublicCodeEntity::Product,
            'v' => PublicCodeEntity::ProductVariant,
            'o' => PublicCodeEntity::Order,
            't' => PublicCodeEntity::Payment,
            's' => PublicCodeEntity::Shipment,
            'a' => PublicCodeEntity::Address,
            'c' => PublicCodeEntity::Category,
        ];

        foreach ($expected as $segment => $entity) {
            $code = PublicCodeGenerator::generate($entity);

            $this->assertMatchesRegularExpression(self::CANONICAL, $code);
            $this->assertSame("bd{$segment}-", substr($code, 0, 4));
            $this->assertSame(6, strlen(substr($code, 4)));
        }
    }

    /** @test */
    public function the_suffix_never_contains_a_visually_ambiguous_character(): void
    {
        // 0/O and 1/I/L are excluded so a code can be read aloud to support or
        // copied off paper without landing on a different record.
        $suffixes = '';

        for ($i = 0; $i < 400; $i++) {
            $suffixes .= substr(PublicCodeGenerator::generate(PublicCodeEntity::Order), 4);
        }

        foreach (['0', 'O', '1', 'I', 'L'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $suffixes);
        }

        $this->assertSame('', preg_replace('/['.PublicCodeGenerator::ALPHABET.']/', '', $suffixes));
    }

    /** @test */
    public function codes_are_random_rather_than_sequential_or_derived(): void
    {
        $codes = [];

        for ($i = 0; $i < 200; $i++) {
            $codes[] = PublicCodeGenerator::generate(PublicCodeEntity::Order);
        }

        // A derived, sequential, or truncated-hash scheme would collide here far
        // more often than chance allows.
        $this->assertGreaterThan(195, count(array_unique($codes)));
    }

    /** @test */
    public function the_namespace_is_normalized_to_lowercase(): void
    {
        config()->set('public_codes.namespace', ' BD ');

        $this->assertSame('bd', PublicCodeGenerator::namespaceValue());
        $this->assertSame('bdo-', PublicCodeGenerator::prefix(PublicCodeEntity::Order));
    }

    /** @test */
    public function a_missing_namespace_fails_loudly(): void
    {
        config()->set('public_codes.namespace', null);

        $this->expectException(PublicCodeGenerationException::class);
        $this->expectExceptionMessage('PUBLIC_CODE_NAMESPACE');

        PublicCodeGenerator::generate(PublicCodeEntity::Order);
    }

    /** @test */
    public function an_invalid_namespace_fails_loudly(): void
    {
        config()->set('public_codes.namespace', 'bd-1!');

        $this->expectException(PublicCodeGenerationException::class);

        PublicCodeGenerator::generate(PublicCodeEntity::Order);
    }

    /** @test */
    public function an_unknown_entity_segment_is_rejected(): void
    {
        $this->expectException(PublicCodeGenerationException::class);

        PublicCodeGenerator::generate('z');
    }

    /** @test */
    public function normalization_folds_user_input_toward_the_stored_form(): void
    {
        $this->assertSame('bdo-Q8M2XC', PublicCodeGenerator::normalize('  BDO-q8m2xc '));
        $this->assertSame('bdo-Q8M2XC', PublicCodeGenerator::normalize('bdo-Q8M2XC'));

        // Ordinary search terms pass through untouched apart from trimming.
        $this->assertSame('blue shirt', PublicCodeGenerator::normalize(' blue shirt '));
    }

    /** @test */
    public function matching_is_whole_string_and_entity_specific(): void
    {
        $this->assertTrue(PublicCodeGenerator::matches('BDO-Q8M2XC', PublicCodeEntity::Order));

        // Right shape, wrong entity.
        $this->assertFalse(PublicCodeGenerator::matches('bdp-Q8M2XC', PublicCodeEntity::Order));
        // Excluded alphabet character.
        $this->assertFalse(PublicCodeGenerator::matches('bdo-Q8M2XO', PublicCodeEntity::Order));
        // Partial codes must never match — otherwise records could be enumerated.
        $this->assertFalse(PublicCodeGenerator::matches('bdo-Q8M2X', PublicCodeEntity::Order));
        $this->assertFalse(PublicCodeGenerator::matches('bdo-Q8M2XCC', PublicCodeEntity::Order));
        $this->assertFalse(PublicCodeGenerator::matches(null, PublicCodeEntity::Order));
    }

    /** @test */
    public function generate_unique_retries_past_taken_candidates(): void
    {
        $rejected = 0;

        $code = PublicCodeGenerator::generateUnique(
            PublicCodeEntity::Order,
            function () use (&$rejected): bool {
                // Report the first three candidates as already taken.
                return $rejected++ < 3;
            },
        );

        $this->assertSame(4, $rejected);
        $this->assertMatchesRegularExpression(self::CANONICAL, $code);
    }

    /** @test */
    public function generate_unique_throws_once_the_attempt_budget_is_exhausted(): void
    {
        $this->expectException(PublicCodeGenerationException::class);
        $this->expectExceptionMessage('bdo-');

        PublicCodeGenerator::generateUnique(
            PublicCodeEntity::Order,
            static fn (): bool => true,
            maxAttempts: 5,
        );
    }

    /** @test */
    public function the_route_pattern_admits_both_the_new_and_the_legacy_format(): void
    {
        $pattern = '#^'.PublicCodeGenerator::routePattern(PublicCodeEntity::Product, '[0-9a-fA-F\-]+').'$#';

        $this->assertMatchesRegularExpression($pattern, 'bdp-K92XMQ');   // new
        $this->assertMatchesRegularExpression($pattern, 'a3f9c1b');      // legacy 7-char hex
        // Reserved literal segments must never be swallowed by the pattern.
        $this->assertDoesNotMatchRegularExpression($pattern, 'admin');
        $this->assertDoesNotMatchRegularExpression($pattern, 'slug');
    }
}
