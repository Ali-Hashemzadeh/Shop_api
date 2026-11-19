<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Support;

use GuzzleHttp\Exception\ConnectException;
use Throwable;

/**
 * Structured inspection of Laravel HTTP-client transport failures.
 *
 * Laravel wraps Guzzle's ConnectException in Illuminate\Http\Client\ConnectionException
 * (as `previous`). The cURL handler records the libcurl error number in the
 * handler context, and CURLE_OPERATION_TIMEDOUT (28) is an unambiguous timeout
 * signal — no message parsing. Non-cURL handlers carry no errno and are
 * reported as plain request failures.
 */
final class HttpFailure
{
    private const CURLE_OPERATION_TIMEDOUT = 28;

    public static function isTimeout(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ConnectException) {
                $errno = $current->getHandlerContext()['errno'] ?? null;

                return (int) $errno === self::CURLE_OPERATION_TIMEDOUT;
            }
        }

        return false;
    }
}
