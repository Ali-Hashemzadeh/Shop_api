<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Support;

/**
 * The single gate between stored diagnostics and an admin API response.
 *
 * The generation ledger and failure reasons can contain raw AI output, external
 * review text (customer content), provider error bodies and, potentially, echoed
 * credentials. Three independent defences apply — masking keys named `token` is
 * not enough on its own:
 *
 *  1. Allow-listing (summary()): only known, scalar, operational fields leave
 *     the ledger by default. Everything else is dropped and only its *key* is
 *     reported in `omitted_fields`.
 *  2. Key-based masking (redactPayload()): in elevated diagnostics, any key that
 *     looks like a credential is replaced wholesale.
 *  3. Value-based redaction (text()): every string is scrubbed for bearer
 *     tokens, `sk-…` keys, `key=value` secrets, JWTs, long hex secrets, emails,
 *     Iranian mobile numbers, the literal configured API keys, and stack traces;
 *     then truncated.
 */
final class DiagnosticSanitizer
{
    public const REDACTED = '[REDACTED]';

    /** Keys whose value is always masked in elevated diagnostics. */
    private const SENSITIVE_KEY = '/(pass(word|wd)?|secret|token|api[_-]?key|authori[sz]ation|cookie|credential|private[_-]?key|signature|bearer|session|otp)/i';

    /** Scalar fields allowed in the default (summary) log view. */
    private const SUMMARY_KEYS = [
        'external_id', 'limit', 'collected', 'generated', 'model', 'review_count', 'count',
        'product_id', 'requested_by', 'approved_by', 'rejected_by', 'draft_id', 'published_review_id',
        'stage', 'failure_code', 'attempt', 'retry_of_id', 'retry_generation_id', 'current_status',
        'drafts', 'duration_ms', 'reconciled_by', 'inactive_seconds', 'reason',
    ];

    /** Lists in the stage-1 analysis that are summarised as counts only. */
    private const ANALYSIS_LISTS = ['positive_points', 'negative_points', 'customer_profiles', 'important_features'];

    private const MAX_DEPTH = 6;

    /**
     * Scrub and truncate a free-text value (failure reason, log error, string field).
     */
    public function text(?string $value, int $max = 500): ?string
    {
        if ($value === null) {
            return null;
        }

        // Never surface a stack trace: cut at the conventional marker and drop
        // `#0 /path/file.php(12): …` frames.
        $value = preg_split('/\R?Stack trace:/i', $value)[0] ?? $value;
        $value = (string) preg_replace('/^#\d+\s.*$/m', '', $value);

        foreach ($this->literalSecrets() as $secret) {
            $value = str_replace($secret, self::REDACTED, $value);
        }

        $patterns = [
            '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i' => 'Bearer '.self::REDACTED,
            '/\b(?:sk|pk|rk|aa)-[A-Za-z0-9_\-]{8,}/' => self::REDACTED,
            '/\b(api[_-]?key|access[_-]?token|refresh[_-]?token|token|secret|password|authorization)(\s*["\']?\s*[:=]\s*["\']?)[^\s"\'&,;}]+/i' => '$1$2'.self::REDACTED,
            '/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/' => self::REDACTED,
            '/\b[A-Fa-f0-9]{32,}\b/' => self::REDACTED,
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[REDACTED_EMAIL]',
            '/(?<!\d)(?:\+98|0098|0)9\d{9}(?!\d)/' => '[REDACTED_PHONE]',
        ];

        $value = (string) preg_replace(array_keys($patterns), array_values($patterns), $value);
        $value = trim((string) preg_replace('/[ \t]*\R+[ \t]*/', ' ', $value));

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max).'…' : $value;
    }

    /**
     * Default log view: allow-listed scalar fields only.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{0: array<string, mixed>|null, 1: list<string>} [summary, omitted keys]
     */
    public function summary(?array $payload): array
    {
        if ($payload === null) {
            return [null, []];
        }

        $summary = [];
        $omitted = [];

        foreach ($payload as $key => $value) {
            $key = (string) $key;

            if ($key === 'prompt' && is_array($value)) {
                $summary['prompt'] = [
                    'name' => isset($value['name']) && is_scalar($value['name']) ? $this->text((string) $value['name'], 100) : null,
                    'version' => isset($value['version']) && is_numeric($value['version']) ? (int) $value['version'] : null,
                ];

                continue;
            }

            if ($key === 'analysis' && is_array($value)) {
                foreach (self::ANALYSIS_LISTS as $list) {
                    $summary['analysis'][$list.'_count'] = is_array($value[$list] ?? null) ? count($value[$list]) : 0;
                }

                continue;
            }

            if (in_array($key, self::SUMMARY_KEYS, true) && ($value === null || is_scalar($value))) {
                $summary[$key] = is_string($value) ? $this->text($value, 200) : $value;

                continue;
            }

            $omitted[] = $key;
        }

        return [$summary, $omitted];
    }

    /**
     * Elevated diagnostics: the full structure, with credential-like keys masked
     * and every string scrubbed + truncated.
     */
    public function redactPayload(mixed $value, int $maxString = 2000, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '[TRUNCATED]';
        }

        if (is_string($value)) {
            return $this->text($value, $maxString);
        }

        if (! is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $key => $item) {
            $out[$key] = is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1
                ? self::REDACTED
                : $this->redactPayload($item, $maxString, $depth + 1);
        }

        return $out;
    }

    /**
     * Configured secret values that must never appear verbatim.
     *
     * @return list<string>
     */
    private function literalSecrets(): array
    {
        $secrets = [(string) config('app.key', '')];

        foreach ((array) config('product_review_ai.ai.providers', []) as $provider) {
            $secrets[] = (string) ($provider['api_key'] ?? '');
        }

        return array_values(array_filter(
            array_unique($secrets),
            static fn (string $s): bool => mb_strlen(trim($s)) >= 6,
        ));
    }
}
