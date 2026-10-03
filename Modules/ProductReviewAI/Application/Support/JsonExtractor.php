<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Support;

use Modules\ProductReviewAI\Domain\Exceptions\AiGenerationException;

/**
 * Turns a model completion into a decoded JSON array. Tolerates ```json fenced
 * blocks and leading/trailing prose, but never guesses — if nothing parses it
 * throws, so a malformed AI response fails the run instead of silently producing
 * empty output.
 */
class JsonExtractor
{
    /**
     * @return array<string, mixed>
     */
    public static function decode(string $raw): array
    {
        $candidate = trim($raw);

        // Strip a ```json ... ``` (or plain ```) fence if present.
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $candidate, $m) === 1) {
            $candidate = trim($m[1]);
        }

        $decoded = json_decode($candidate, true);

        if (! is_array($decoded)) {
            // Last resort: grab the outermost {...} block.
            $start = strpos($candidate, '{');
            $end = strrpos($candidate, '}');

            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($candidate, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($decoded)) {
            throw new AiGenerationException('AI response was not valid JSON: '.mb_substr($raw, 0, 300));
        }

        return $decoded;
    }
}
